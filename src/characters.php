<?php
/**
 * Who you have already got, in one module, grouped by the party they march in.
 *
 * Split out of index.php, which used to list every character you had ever made
 * in a single column beside the modules. That worked at six characters and
 * stopped working somewhere around thirty: a flat list cannot answer either of
 * the two questions actually being asked — which game is this one in, and who
 * do they travel with.
 *
 * The module is the page's whole scope, so it comes from the query string and
 * everything here is about that one game. A party is the unit of a playthrough
 * — quests, flags and companions are all party-scoped — so a party is the unit
 * this page groups by.
 */
require_once __DIR__ . '/app/page_guard.php';
require_signed_in_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Your parties — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
  <?php require APP_PATH . '/inc/site_bar.php'; ?>
  <header class="hero hero-slim">
    <h1 id="module-name">Your parties</h1>
    <p id="module-blurb" class="hero-sub"></p>
    <div class="hero-actions">
      <a class="btn" href="index.php"><?= ADVENTURES_ENABLED ? 'All modules' : 'Home' ?></a>
      <a class="btn btn-primary" id="btn-new" href="create.php">Start a new party here</a>
    </div>
  </header>

  <main class="container">
    <div id="error-banner" class="error-banner hidden"></div>
    <div id="parties"><p class="help-hint">Loading…</p></div>
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

    const MODULE = new URLSearchParams(location.search).get('module') || '';

    /**
     * Everything the account owns, cut down to this module and grouped.
     *
     * The grouping is done here rather than in SQL because `session/list`
     * already returns exactly the right rows and is the one place ownership is
     * decided — a second query with its own WHERE is a second place to get that
     * wrong, and getting it wrong means showing somebody else's saves.
     *
     * A character with no party is possible (the row is created before the
     * party is joined, and an old save may predate parties entirely), so those
     * are collected under their own heading rather than dropped.
     */
    function groupByParty(characters) {
      const parties = new Map();
      const loose = [];
      for (const c of characters) {
        if (!c.party_id) {
          loose.push(c);
          continue;
        }
        if (!parties.has(c.party_id)) {
          parties.set(c.party_id, { id: c.party_id, name: c.party_name, members: [] });
        }
        parties.get(c.party_id).members.push(c);
      }
      return { parties: [...parties.values()], loose };
    }

    /**
     * A member's card. No Play button of its own when it sits in a party.
     *
     * You do not play a character here, you resume a party — the game is a
     * party's playthrough, and which member you happen to be looking through is
     * a thing you change in the party rail once you are in it. Six Play buttons
     * on six cards read as six games, and picking the wrong face was a decision
     * the player had no basis to make from this page.
     *
     * A character with no party keeps one, because there is no party to press.
     */
    function cardHtml(c, standalone) {
      // The face, not the bust — the same cut the party rail and the people
      // list use at this size. alt="" because the name is right beside it.
      const face = c.sprite_key
        ? `<img class="char-face" alt="" loading="lazy"
                src="assets/images/npcs/${encodeURIComponent(c.sprite_key)}_face.png"
                onerror="this.remove()">`
        : '';
      const hurt = c.max_hp > 0 && c.current_hp / c.max_hp <= 0.34 ? ' is-hurt' : '';
      const play = standalone
        ? `<button type="button" class="icon-btn icon-btn-sm char-act play-btn"
               title="Play ${esc(c.name)}" aria-label="Play ${esc(c.name)}"
               data-id="${esc(c.id)}">
              <svg aria-hidden="true"><use href="#i-play"></use></svg></button>`
        : '';
      // The actions are a third column of the card, not a row under the name:
      // a corner is where a card's controls are looked for, and stacked under
      // the text they read as part of the character rather than as things to
      // press. Outside `.char-body` so the name can never wrap beneath them.
      return `<div class="char-card">
        ${face}
        <div class="char-body">
          <div class="name">${esc(c.name)}</div>
          <div class="sub">${esc(c.race)}${c.subrace ? ' / ' + esc(c.subrace) : ''}
            · ${esc(c.class)} ${esc(c.level)}
            · <span class="char-hp${hurt}">HP ${esc(c.current_hp)}/${esc(c.max_hp)}</span></div>
        </div>
        <div class="char-actions">
          ${play}
          <a class="icon-btn icon-btn-sm char-act" title="Change ${esc(c.name)}'s look"
             aria-label="Change ${esc(c.name)}'s look"
             href="look.php?character_id=${encodeURIComponent(c.id)}">
            <svg aria-hidden="true"><use href="#i-face"></use></svg></a>
          <a class="icon-btn icon-btn-sm char-act" title="Print ${esc(c.name)}'s sheet"
             aria-label="Print ${esc(c.name)}'s sheet"
             href="sheet_print.php?character_id=${encodeURIComponent(c.id)}">
            <svg aria-hidden="true"><use href="#i-print"></use></svg></a>
        </div>
      </div>`;
    }

    (async function () {
      const host = document.getElementById('parties');

      // The module, for the heading and the create link. A page reached without
      // one still works and simply shows everything — better than an error for
      // a bookmark that predates the parameter.
      let module = null;
      try {
        const mods = (await API.get('session/modules')).modules || [];
        module = mods.find((m) => m.module_key === MODULE) || null;
      } catch (e) { /* the heading falls back to its own text */ }

      if (module) {
        document.title = module.name + ' — your parties';
        document.getElementById('module-name').textContent = module.name;
        document.getElementById('module-blurb').textContent = module.blurb || '';
        document.getElementById('btn-new').href =
          'create.php?module=' + encodeURIComponent(module.module_key);
      }

      let characters = [];
      try {
        characters = (await API.get('session/list')).characters || [];
      } catch (e) {
        host.innerHTML = '<p class="help-hint">Could not read your characters.</p>';
        return;
      }
      if (MODULE) {
        characters = characters.filter((c) => c.module_key === MODULE);
      }

      if (!characters.length) {
        host.innerHTML = `<section class="panel">
          <h2>Nothing started here yet</h2>
          <p class="help-hint">No party of yours has set foot in this one.
             Start a new party to begin.</p>
        </section>`;
        return;
      }

      const { parties, loose } = groupByParty(characters);
      const blocks = [];

      for (const p of parties) {
        // Highest level first inside a party: the leader is usually the one
        // furthest along, and a party reads better led by somebody.
        p.members.sort((a, b) => (b.level - a.level) || (a.id - b.id));
        const n = p.members.length;
        blocks.push(`<section class="panel party-block">
          <div class="party-head">
            <h2>${esc(p.name || 'A party')}</h2>
            <span class="party-count">${n} member${n === 1 ? '' : 's'}</span>
            <button type="button" class="btn btn-primary btn-small party-play play-btn"
               data-party="${esc(p.id)}"
               aria-label="Play ${esc(p.name || 'this party')}">
              <svg aria-hidden="true"><use href="#i-play"></use></svg>
              Play</button>
          </div>
          <div class="party-members">${p.members.map((c) => cardHtml(c, false)).join('')}</div>
        </section>`);
      }

      if (loose.length) {
        blocks.push(`<section class="panel party-block">
          <div class="party-head">
            <h2>Not in a party</h2>
            <span class="party-count">${loose.length} character${loose.length === 1 ? '' : 's'}</span>
          </div>
          <p class="help-hint">These belong to no party — an old save, or one
             that never finished being made.</p>
          <div class="party-members">${loose.map((c) => cardHtml(c, true)).join('')}</div>
        </section>`);
      }

      host.innerHTML = blocks.join('');
    })();

    /**
     * Play is a POST and then a navigation, not a link.
     *
     * `game.php` reads no query string at all — it plays whoever the session
     * says is active. So the character has to be made active on the server
     * first; a bare href to `game.php?character_id=N` would look like it
     * worked and drop you into the last character you played, which is the
     * kind of bug that gets blamed on the save rather than the link.
     *
     * A party's button sends the party and lets `session/select` work out who
     * that means. The page could sort the members and send the first itself,
     * but then two places would hold the rule about who leads a party and the
     * client's copy would be the one that never sees `leader_character_id`.
     *
     * Delegated from the container so it survives the list being rebuilt.
     */
    document.getElementById('parties').addEventListener('click', async (e) => {
      const btn = e.target.closest('.play-btn');
      if (!btn) return;
      btn.disabled = true;
      try {
        await API.post('session/select', btn.dataset.party
          ? { party_id: Number(btn.dataset.party) }
          : { character_id: Number(btn.dataset.id) });
        location.href = 'game.php';
      } catch (err) {
        btn.disabled = false;
        const banner = document.getElementById('error-banner');
        banner.textContent = err.message || 'Could not open that character.';
        banner.classList.remove('hidden');
      }
    });
  </script>

  <svg class="icon-sprite" aria-hidden="true" focusable="false">
    <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5l11 7-11 7z"/></symbol>
    <!-- A head in profile: the look, not the sheet. -->
    <symbol id="i-face" viewBox="0 0 24 24"><path d="M12 3a5 5 0 0 1 5 5v1.5l1.4 2.6a.7.7 0 0 1-.6 1H16v2.4a2 2 0 0 1-2 2h-1.5V21H10v-3.6a6.6 6.6 0 0 1-3-5.5V8a5 5 0 0 1 5-5zm-1.6 5.6a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></symbol>
    <symbol id="i-print" viewBox="0 0 24 24">
      <path d="M7 9V3h10v6"/><path d="M5 9h14a2 2 0 012 2v6h-4"/>
      <path d="M7 14h10v7H7z"/>
    </symbol>
  </svg>
</body>
</html>
