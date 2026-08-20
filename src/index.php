<?php
/**
 * The front page: your characters on the right, whoever you pick on the left.
 *
 * This replaced the shelf of modules, which asked the wrong question first.
 * "Which adventure?" is a question with exactly one right answer for a player
 * who already has a party going — the one their party is in — and answering it
 * cost two more pages before anybody was playing: a module card, then that
 * module's party list, then Play. What a returning player actually wants to say
 * is which *character* they are, and the game they are in is a fact about that
 * character rather than a choice to be made again.
 *
 * So the list is the page. Clicking a name opens their sheet beside it, and the
 * sheet carries the Play button with the name of the adventure they are on
 * written on it — which is the only place the module is named, because by then
 * it is an answer and not a question.
 *
 * The shelf is not gone; it has become the other thing the detail pane can
 * show. Starting a NEW game is the one time "which adventure?" is a real
 * question, so that is when it is asked — behind "New character" — and the
 * module cards are the same cards, with the same cover art and the same two
 * doors, drawn into the left-hand pane instead of across the top of the page.
 */
require_once __DIR__ . '/app/page_guard.php';
require_signed_in_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rivermark Chronicles — Open 5e RPG</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="home-page picker-page">
  <?php require APP_PATH . '/inc/site_bar.php'; ?>

  <!--
    Identity, not an entrance. The eyebrow — "Open 5e SRD · single player · in
    the browser" — was a pitch, and everyone who reaches this page has already
    signed in and bought it; what it cost was two lines of vertical space above
    the only thing on the page anybody came for.
  -->
  <header class="hero hero-slim">
    <h1>Rivermark Chronicles</h1>
    <div class="rule-orn" aria-hidden="true"></div>
  </header>

  <main class="container">
    <div id="error-banner" class="error-banner hidden"></div>

    <!--
      Two panes, and the rail comes first in the source on purpose.

      On a wide screen CSS puts it in the second column, which is where the
      list belongs — you read the sheet, and the list is the index down the
      edge of it. On a narrow one there are no columns and source order is the
      order, so the list is what you land on and the sheet unfolds underneath
      whoever you tapped. A sheet-first stack would open on somebody you had
      not chosen yet and bury the choosing below it.
    -->
    <div class="picker">
      <aside class="picker-rail">
        <div class="rail-head">
          <h2>Your characters</h2>
          <button type="button" class="btn btn-small" id="btn-new"
                  title="Start a new party, in whichever adventure you like">New</button>
        </div>
        <div id="char-list" class="rail-list"><p class="help-hint">Loading…</p></div>
      </aside>

      <!--
        No `aria-live` on this pane, deliberately. Swapping it announces a whole
        character sheet — eight pills, six saves, eighteen skills — every time
        somebody moves down the list, which is worse than announcing nothing.
        The button that was pressed carries `aria-current` instead, and the
        sheet is the next thing after the list in source order, so tabbing on
        from the name you chose walks into it.
      -->
      <section class="picker-detail" id="detail">
        <p class="help-hint">Loading…</p>
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
    /** A modifier reads as a modifier: +3, not 3. */
    function mod(n) {
      const v = Number(n) || 0;
      return (v >= 0 ? '+' : '−') + Math.abs(v);
    }

    const state = {
      characters: [],
      modules: [],
      selected: null,      // character id whose sheet is open
      sheets: new Map(),   // id -> the payload, so flicking through the list is free
    };

    // =====================================================================
    // The rail
    // =====================================================================

    /**
     * The list, grouped by the adventure each character is in.
     *
     * A module is a property of the PARTY, so a character who never joined one
     * has no adventure to be grouped under — an old save, or one abandoned
     * halfway through being made. Those collect at the bottom under their own
     * heading rather than being dropped, because a character you cannot see is
     * a character you cannot delete either.
     *
     * The group order follows the module catalogue rather than the characters,
     * so the adventures stay in the same order here as they do on the shelf and
     * a new character does not reshuffle the list.
     */
    function groupCharacters() {
      const order = new Map(state.modules.map((m, i) => [m.module_key, i]));
      const groups = new Map();
      for (const c of state.characters) {
        const key = c.module_key || '';
        if (!groups.has(key)) {
          groups.set(key, {
            key,
            name: c.module_name || 'Not in an adventure',
            rank: order.has(key) ? order.get(key) : (c.module_key ? 900 : 999),
            members: [],
          });
        }
        groups.get(key).members.push(c);
      }
      return [...groups.values()].sort((a, b) => a.rank - b.rank);
    }

    /**
     * One character, as a button.
     *
     * A button and not a link: nothing navigates. The click swaps what the
     * other pane is showing, which is what a button is for, and it means the
     * whole list is reachable by keyboard with no roving-tabindex machinery.
     *
     * The party name sits under the class line because two characters of yours
     * can be a level 3 Human Fighter in the same adventure and be in different
     * parties — which party is then the only thing that tells them apart.
     */
    function railCard(c) {
      const face = c.sprite_key
        ? `<img class="char-face" alt="" loading="lazy"
                src="assets/images/npcs/${encodeURIComponent(c.sprite_key)}_face.png"
                onerror="this.remove()">`
        : '';
      const hurt = c.max_hp > 0 && c.current_hp / c.max_hp <= 0.34 ? ' is-hurt' : '';
      return `<button type="button" class="char-card rail-pick${state.selected == c.id ? ' is-active' : ''}"
              data-pick="${esc(c.id)}"${state.selected == c.id ? ' aria-current="true"' : ''}>
        ${face}
        <span class="char-body">
          <span class="name">${esc(c.name)}</span>
          <span class="sub">${esc(c.race)}${c.subrace ? ' / ' + esc(c.subrace) : ''}
            · ${esc(c.class)} ${esc(c.level)}
            · <span class="char-hp${hurt}">HP ${esc(c.current_hp)}/${esc(c.max_hp)}</span></span>
          ${c.party_name ? `<span class="sub rail-party">${esc(c.party_name)}</span>` : ''}
        </span>
      </button>`;
    }

    function renderRail() {
      const host = document.getElementById('char-list');
      if (!state.characters.length) {
        host.innerHTML = `<p class="help-hint">Nobody yet. Pick an adventure and
          make somebody to walk into it.</p>`;
        return;
      }
      host.innerHTML = groupCharacters().map((g) => `
        <div class="rail-group">
          <h3 class="rail-group-head">${esc(g.name)}</h3>
          ${g.members.map(railCard).join('')}
        </div>`).join('');
    }

    // =====================================================================
    // The detail pane — a sheet, or the shelf
    // =====================================================================

    /**
     * The module cards, drawn where the sheet would be.
     *
     * Unchanged in substance from the shelf that used to be this page: the
     * cover plate is the whole top of the card with the name lying across it,
     * a badge counts what you already have going there, and there are two
     * doors. What changed is when it is asked for — starting a new game — and
     * so which door is loud: Play is still offered where you have parties,
     * because arriving here from "New" and then remembering you meant to
     * resume is a real thing to do.
     *
     * Cover art is by convention rather than by a column, and a missing file is
     * answered by the vhost with the homepage HTML and a 200 — so `onerror` is
     * the only warning the browser gets, and `.no-art` drops the 3:2 rather
     * than leaving a dark rectangle that reads as a picture that failed.
     */
    function renderShelf(heading) {
      state.selected = null;
      renderRail();
      const host = document.getElementById('detail');
      host.innerHTML = `
        <div class="section-head">
          <h2>${esc(heading)}</h2>
          <p class="section-hint">Each one is a separate game. A party is made in
            one and stays in it.</p>
        </div>
        <div class="shelf-grid" id="shelf"></div>

        <!--
          The how-to, kept and moved rather than dropped with the old shelf.
          It only ever helped somebody who had not started yet, and this pane
          is where that person now is — under the cards, at the moment they are
          choosing which game to walk into.
        -->
        <section class="panel home-howto">
          <h2>How to play</h2>
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
        </section>`;

      const shelf = document.getElementById('shelf');
      if (!state.modules.length) {
        shelf.innerHTML = `<p class="help-hint">No adventures are installed.</p>`;
        return;
      }

      state.modules.forEach((m) => {
        const n = Number(m.party_count) || 0;
        const key = encodeURIComponent(m.module_key);
        const card = document.createElement('section');
        card.className = 'panel module-card';
        card.innerHTML = `
          <a class="module-plate" href="create.php?module=${key}">
            <img class="module-cover" alt="" src="assets/images/modules/${key}.jpg">
            ${n ? `<span class="module-badge">${n} part${n === 1 ? 'y' : 'ies'}</span>` : ''}
            <span class="module-cap">
              <span class="module-name">${esc(m.name)}</span>
              <span class="module-levels">Levels ${esc(m.level_min)}–${esc(m.level_max)}</span>
            </span>
          </a>
          <div class="module-actions">
            <a class="btn btn-small btn-primary" href="create.php?module=${key}">New party</a>
            ${n ? `<a class="btn btn-small" href="characters.php?module=${key}">Parties here</a>` : ''}
          </div>
          <div class="module-body">
            ${m.blurb ? `<p class="module-blurb">${esc(m.blurb)}</p>` : ''}
            ${m.attribution ? `<p class="module-credit">${esc(m.attribution)}</p>` : ''}
          </div>`;
        const cover = card.querySelector('.module-cover');
        cover.addEventListener('error', () => {
          cover.remove();
          card.querySelector('.module-plate').classList.add('no-art');
        });
        shelf.appendChild(card);
      });
    }

    /** A row of the saves or skills column. */
    function statRow(label, m, prof, note) {
      return `<li class="stat-line${prof ? ' is-prof' : ''}">
        <span class="stat-dot" aria-hidden="true"></span>
        <span class="stat-name">${esc(label)}</span>
        ${note ? `<span class="stat-note">${esc(note)}</span>` : ''}
        <span class="stat-mod">${esc(mod(m))}</span>
      </li>`;
    }

    /**
     * The sheet.
     *
     * Every number on it arrives from `session/sheet`, which is CharacterSheet,
     * which is Rules — the same Rules the combat engine and the printed sheet
     * ask. Nothing here adds a proficiency bonus to anything. A second rules
     * engine in a front-page script would be wrong in exactly the places
     * nobody ever checks, and the printed sheet's own header says why that
     * matters more than it sounds.
     */
    function renderSheet(payload) {
      const s = payload.sheet;
      const ctx = payload.context || {};
      const c = s.character;
      const host = document.getElementById('detail');

      const where = [ctx.party_name, ctx.location_name].filter(Boolean).join(' · ');
      // The Play button says which game it opens. That is the whole reason the
      // module is looked up: on the old shelf you chose the adventure and then
      // the character, so the button did not have to say — here you have chosen
      // a person, and "Play" alone would not tell you what you were about to
      // walk into. A character with no party has no module and no game to
      // resume; they get the creator instead of a button that would open one.
      const play = ctx.party_id
        ? `<div class="play-doors">
             <button type="button" class="btn btn-primary btn-lg play-btn"
                     data-party="${esc(ctx.party_id)}">
               <svg aria-hidden="true"><use href="#i-play"></use></svg>
               Play ${esc(ctx.module_name || 'this adventure')}
             </button>
             <button type="button" class="btn btn-lg fight-btn"
                     data-party="${esc(ctx.party_id)}"
                     title="A fight built to this party's size and level, taken where they stand. Worth exactly what the same monsters are worth anywhere else.">
               <svg aria-hidden="true"><use href="#i-swords"></use></svg>
               Random encounter
             </button>
           </div>
           ${where ? `<p class="play-where">${esc(where)}</p>` : ''}`
        : `<p class="help-hint">This one never joined a party, so there is no
             game to resume.</p>`;

      /*
       * How far off the next level is.
       *
       * On the sheet because of the button beside it: a random encounter is
       * offered here as a way of earning experience, and a page that offers
       * that without ever showing what it bought is asking the player to take
       * it on trust. `xp_progress` is Rules::xpProgress, computed on the server
       * and carried on every character — the ladder is never restated here.
       */
      const xp = c.xp_progress;
      const xpBar = xp ? `
        <div class="bar-row pick-xp">
          <span class="bar-label">${xp.next_level === null
            ? 'Experience' : 'To level ' + esc(xp.next_level)}</span>
          <div class="xp-bar"><span style="width:${esc(xp.percent)}%"></span></div>
          <span class="bar-value">${xp.next_level === null
            ? esc(xp.xp) + ' XP · max level'
            : esc(xp.earned) + ' / ' + esc(xp.needed)}</span>
        </div>` : '';

      const abilities = s.abilities.map((a) => `
        <div class="ability-box sheet-ab">
          <span>${esc(a.abbr)}</span><strong>${esc(a.score)}</strong><em>${esc(mod(a.mod))}</em>
        </div>`).join('');

      const attacks = s.attacks.map((a) => `
        <tr${a.equipped ? ' class="is-equipped"' : ''}>
          <td>${esc(a.name)}${a.equipped ? ' <span class="badge ok">worn</span>' : ''}</td>
          <td>${esc(mod(a.bonus))}</td>
          <td>${esc(a.damage)} ${esc(a.damage_type || '')}</td>
          <td class="stat-note">${esc(a.notes || a.reach || '')}</td>
        </tr>`).join('');

      const features = s.features.map((f) => `
        <li><strong>${esc(f.name)}</strong>
          <span class="stat-note">${esc(f.source)}</span>
          ${f.detail ? `<p class="inv-desc">${esc(f.detail)}</p>` : ''}</li>`).join('');

      // Spell slots are the server's arithmetic too — `left` is shipped, not
      // worked out from have-minus-used in the browser.
      const sc = s.spellcasting;
      const spells = sc ? `
        <div class="sheet-section">
          <h3>Spellcasting</h3>
          <div class="sheet-combat-row">
            <div class="sheet-stat-pill"><span>Ability</span><strong>${esc(sc.ability)}</strong></div>
            <div class="sheet-stat-pill"><span>Save DC</span><strong>${esc(sc.save_dc)}</strong></div>
            <div class="sheet-stat-pill"><span>Attack</span><strong>${esc(mod(sc.attack_bonus))}</strong></div>
            ${sc.slots.map((sl) => `<div class="sheet-stat-pill">
              <span>Level ${esc(sl.level)}</span><strong>${esc(sl.left)}/${esc(sl.have)}</strong></div>`).join('')}
          </div>
          <ul class="sheet-list spell-list">
            ${sc.known.map((sp) => `<li><strong>${esc(sp.name)}</strong>
              <span class="stat-note">${sp.level == 0 ? 'Cantrip' : 'Level ' + esc(sp.level)}
                · ${esc(sp.school || '')}</span></li>`).join('')}
          </ul>
        </div>` : '';

      host.innerHTML = `
        <article class="pick-sheet">
          <div class="sheet-header">
            <img class="sheet-bust" alt=""
                 src="assets/images/npcs/${encodeURIComponent(c.sprite_key || 'fighter')}_bust.png"
                 onerror="this.remove()">
            <div class="sheet-ident">
              <h2 class="sheet-title">${esc(c.name)}</h2>
              <p class="sheet-subtitle">
                Level ${esc(c.level)} ${esc(c.race)}${c.subrace ? ' (' + esc(c.subrace) + ')' : ''}
                ${esc(c.class)}${c.subclass ? ' · ' + esc(c.subclass) : ''}
              </p>
              <p class="sheet-subtitle">${esc(c.background || '')}${c.alignment ? ' · ' + esc(c.alignment) : ''}</p>
            </div>
            <div class="sheet-header-actions">
              <a class="btn btn-small" target="_blank" rel="noopener"
                 href="sheet_print.php?character_id=${esc(c.id)}&amp;print=1"
                 title="A paper sheet for the table, in the standard 5e layout. Opens in a new tab.">Print</a>
            </div>
          </div>

          <div class="pick-play${ctx.party_id ? '' : ' is-idle'}">${play}</div>

          <div class="sheet-combat-row">
            <div class="sheet-stat-pill"><span>HP</span><strong>${esc(c.current_hp)}/${esc(c.max_hp)}</strong></div>
            <div class="sheet-stat-pill"><span>AC</span><strong>${esc(c.armor_class)}</strong></div>
            <div class="sheet-stat-pill"><span>Speed</span><strong>${esc(c.speed)} ft</strong></div>
            <div class="sheet-stat-pill"><span>Prof</span><strong>${esc(mod(s.proficiency_bonus))}</strong></div>
            <div class="sheet-stat-pill"><span>Init</span><strong>${esc(mod(s.initiative))}</strong></div>
            <div class="sheet-stat-pill" title="Passive Perception"><span>Passive</span><strong>${esc(s.passive_perception)}</strong></div>
            <div class="sheet-stat-pill"><span>Hit dice</span><strong>${esc(s.hit_dice)}</strong></div>
            <div class="sheet-stat-pill"><span>Gold</span><strong>${esc(c.gold ?? 0)} gp</strong></div>
          </div>
          ${xpBar}

          <div class="sheet-section">
            <h3>Ability scores</h3>
            <div class="ability-grid sheet-abilities">${abilities}</div>
          </div>

          <div class="sheet-cols">
            <div class="sheet-section">
              <h3>Saving throws</h3>
              <ul class="sheet-list">
                ${s.saves.map((v) => statRow(v.label, v.mod, v.proficient, '')).join('')}
              </ul>
            </div>
            <div class="sheet-section">
              <h3>Skills</h3>
              <ul class="sheet-list skill-list">
                ${s.skills.map((v) => statRow(v.label, v.mod, v.proficient || v.expertise,
                    v.expertise ? v.ability + ' ×2' : v.ability)).join('')}
              </ul>
            </div>
          </div>

          <div class="sheet-section">
            <h3>Attacks</h3>
            <table class="sheet-table">
              <thead><tr><th>Weapon</th><th>Hit</th><th>Damage</th><th></th></tr></thead>
              <tbody>${attacks}</tbody>
            </table>
          </div>

          ${spells}

          <div class="sheet-cols">
            <div class="sheet-section">
              <h3>Features &amp; traits</h3>
              <ul class="sheet-list">${features}</ul>
            </div>
            <div class="sheet-section">
              <h3>Proficiencies</h3>
              <ul class="sheet-list">
                ${s.proficiencies.map((p) => `<li><strong>${esc(p.label)}</strong>
                  <p class="inv-desc">${esc(p.value)}</p></li>`).join('')}
              </ul>
              <h3>Carrying</h3>
              <p class="inv-desc">${esc(s.inventory.length)} things, ${esc(s.carried_weight)} lb.</p>
            </div>
          </div>
        </article>`;
    }

    /**
     * Open somebody's sheet.
     *
     * Cached by id, because the list invites flicking through it and the sheet
     * is a heavy payload — every spell a wizard knows arrives with its prose.
     * Nothing on this page changes a character, so a cached sheet cannot go
     * stale while it is being looked at.
     */
    async function showSheet(id, pressed) {
      state.selected = id;
      renderRail();
      const host = document.getElementById('detail');
      /*
       * Stacked, the sheet is below the whole list and a click looks like it
       * did nothing. Only when somebody pressed a name — doing it on the sheet
       * the page opens by itself would scroll the list off the top of a phone
       * before the player had read it.
       */
      const stacked = window.matchMedia('(max-width: 62rem)').matches;
      if (pressed && stacked) host.scrollIntoView({ behavior: 'smooth', block: 'start' });
      if (state.sheets.has(id)) {
        renderSheet(state.sheets.get(id));
        return;
      }
      host.innerHTML = '<p class="help-hint">Reading the sheet…</p>';
      try {
        const payload = await API.get('session/sheet', { character_id: id });
        state.sheets.set(id, payload);
        if (state.selected === id) renderSheet(payload);
      } catch (e) {
        host.innerHTML = `<p class="help-hint">Could not read that sheet.</p>`;
        showError(e.message);
      }
    }

    function showError(message) {
      const banner = document.getElementById('error-banner');
      banner.textContent = message || 'Something went wrong.';
      banner.classList.remove('hidden');
    }

    // =====================================================================
    // Wiring
    // =====================================================================

    document.getElementById('char-list').addEventListener('click', (e) => {
      const btn = e.target.closest('[data-pick]');
      if (btn) showSheet(Number(btn.dataset.pick), true);
    });

    document.getElementById('btn-new').addEventListener('click', () => {
      renderShelf('Which adventure?');
    });

    /**
     * Play is a POST and then a navigation, not a link.
     *
     * `game.php` reads no query string at all — it plays whoever the session
     * says is active. So the party has to be made active on the server first;
     * a bare href would look like it worked and drop you into the last
     * character you played.
     *
     * The party is sent rather than the character, and `session/select` works
     * out who that means: it prefers whoever you were last playing in that
     * party, then the party's leader. The page could sort the members and send
     * the first itself, but then two places would hold the rule about who leads
     * a party and the client's copy is the one that never sees
     * `leader_character_id`.
     */
    document.getElementById('detail').addEventListener('click', async (e) => {
      const btn = e.target.closest('.play-btn, .fight-btn');
      if (!btn) return;
      const fight = btn.classList.contains('fight-btn');
      btn.disabled = true;
      try {
        await API.post('session/select', { party_id: Number(btn.dataset.party) });
        /*
         * The fight is arranged BEFORE the page changes, not after.
         *
         * game.php reads `session/status` on boot and opens in combat when the
         * session has an active fight, so a skirmish started here is already
         * on the board by the time the game draws itself — one navigation, and
         * no moment where the player is standing on a map wondering whether
         * the button worked. It is also the only order that can report a
         * refusal: a party with nobody on their feet is told so here, on a
         * page that can say it, rather than in a game they have just been sent
         * to.
         */
        if (fight) await API.post('combat/random', {});
        location.href = 'game.php';
      } catch (err) {
        btn.disabled = false;
        showError(err.message || (fight
          ? 'Could not find them a fight.'
          : 'Could not open that game.'));
      }
    });

    (async function () {
      const [mods, list] = await Promise.all([
        API.get('session/modules').catch(() => ({ modules: [] })),
        API.get('session/list').catch((e) => { showError(e.message); return { characters: [] }; }),
      ]);
      state.modules = mods.modules || [];
      state.characters = list.characters || [];

      if (!state.characters.length) {
        renderRail();
        renderShelf('Start your first adventure');
        return;
      }

      /*
       * Which sheet is open when the page lands.
       *
       * Whoever the session was last playing, if they are still in the list,
       * and the top of the list otherwise. This is deliberately a SHOW and not
       * a Continue button: the old page had one, and it went to `game.php`
       * without saying where — you pressed it and found out. Opening their
       * sheet answers the same want by naming the character, the party and the
       * adventure first, and leaves the pressing to you.
       *
       * Best effort. If `session/status` is unhappy the list still works.
       */
      let last = null;
      try {
        last = (await API.get('session/status')).character_id || null;
      } catch (e) { /* the top of the list is a fine answer */ }
      const wanted = state.characters.some((c) => c.id == last)
        ? Number(last)
        : Number(state.characters[0].id);
      renderRail();
      showSheet(wanted);
    })();
  </script>

  <svg class="icon-sprite" aria-hidden="true" focusable="false">
    <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5l11 7-11 7z"/></symbol>
    <!-- Crossed blades. Stroked rather than filled, so it reads beside the
         solid play triangle as the quieter of the two doors. -->
    <symbol id="i-swords" viewBox="0 0 24 24">
      <path d="M4 3l10 10M20 3L10 13" stroke="currentColor" stroke-width="2"
            fill="none" stroke-linecap="round"/>
      <path d="M3 17l4 4M21 17l-4 4M13 15l-4 4" stroke="currentColor"
            stroke-width="2" fill="none" stroke-linecap="round"/>
    </symbol>
  </svg>
</body>
</html>
