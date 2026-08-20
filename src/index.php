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
      fetching: new Set(), // ids already on their way, so a prefetch is asked once
      // Who marches out when a fight is asked for, per party. A Set of
      // character ids, seeded from whoever is on their feet the first time a
      // party's sheet is drawn and then left to the player. Per page load:
      // "who am I taking THIS time" is a decision about the next ten minutes,
      // not a setting about the character.
      joining: new Map(),   // party_id -> Set(character id)
      arming: null,         // the character whose Retire has been pressed once
      // Which face of the detail pane is up: the sheet, the bag, or the store.
      // Reset to the sheet whenever the character changes, because a bag is
      // somebody's and looking at somebody else means looking at theirs.
      view: 'sheet',
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

    /**
     * Who walks out with you, for one party.
     *
     * Seeded the first time a party is drawn with everybody on their feet,
     * which is what a player who never touches a tick would expect, and then
     * owned by the player. Held per party rather than globally because two
     * parties are two decisions, and flicking between them must not carry one
     * party's choice into the other's tabs.
     *
     * The downed are never in it. Anybody knocked out since the set was made is
     * dropped on the way past, so a tick cannot outlive the character it
     * belonged to falling over.
     */
    function joiners(partyId, party) {
      if (!partyId) return new Set();
      let set = state.joining.get(partyId);
      if (!set) {
        set = new Set(party.filter((m) => m.current_hp > 0).map((m) => Number(m.id)));
        state.joining.set(partyId, set);
      }
      party.forEach((m) => { if (m.current_hp <= 0) set.delete(Number(m.id)); });
      return set;
    }

    /**
     * The party strip.
     *
     * Drawn above the sheet and above the bag, because both are about one
     * person and both are things you want for the next person along without
     * going back to a list first. Pressing one keeps you where you are — read a
     * sheet, press a name, read that sheet; open a bag, press a name, open that
     * bag — which is `data-keep` on the button and is the difference between
     * these and the rail down the right, where pressing a name means "show me
     * somebody else" and lands on their sheet.
     *
     * `ticks` is the fight roster, and only the sheet wants it: a tick is about
     * who walks out to a fight and would be a question with no meaning over a
     * list of somebody's rope and rations.
     */
    function partyTabs(party, currentId, partyId, ticks) {
      if (!party || party.length < 2) return '';
      const coming = ticks ? joiners(partyId, party) : null;
      return `<div class="party-tabs" role="tablist" aria-label="Party members">
        ${party.map((m) => {
          const hurt = m.max_hp > 0 && m.current_hp / m.max_hp <= 0.34 ? ' is-hurt' : '';
          const on = m.id == currentId;
          const down = m.current_hp <= 0;
          return `<div class="party-tab${on ? ' is-active' : ''}${m.companion ? ' is-companion' : ''}${down ? ' is-down' : ''}">
            ${coming
              ? `<input type="checkbox" class="tab-join" data-join="${esc(m.id)}"
                        ${coming.has(Number(m.id)) ? 'checked' : ''} ${down ? 'disabled' : ''}
                        aria-label="${down
                          ? esc(m.name) + ' is down and cannot fight'
                          : 'Take ' + esc(m.name) + ' into the fight'}"
                        title="${down
                          ? esc(m.name) + ' is down and cannot fight.'
                          : 'Take ' + esc(m.name) + ' into a random encounter'}">`
              : ''}
            <button type="button" class="tab-open" role="tab"
                    aria-selected="${on ? 'true' : 'false'}"
                    data-pick="${esc(m.id)}" data-keep="1"
                    title="${m.companion
                      ? esc(m.name) + ' travels with you.'
                      : esc(m.name)}">
              ${m.sprite_key
                ? `<img class="tab-face" alt="" loading="lazy"
                       src="assets/images/npcs/${encodeURIComponent(m.sprite_key)}_face.png"
                       onerror="this.remove()">`
                : ''}
              <span class="tab-body">
                <span class="tab-name">${esc(m.name)}</span>
                <span class="tab-sub">${esc(m.class)} ${esc(m.level)} ·
                  <span class="char-hp${hurt}">${esc(m.current_hp)}/${esc(m.max_hp)}</span>${
                    m.companion ? ' · <span class="tab-comp">companion</span>' : ''}</span>
              </span>
            </button>
          </div>`;
        }).join('')}
      </div>`;
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
      /*
       * Retiring somebody, in two presses.
       *
       * A confirmation, because it is the one control on this page that takes
       * a character away — and an in-page one rather than `confirm()`, which
       * is a browser modal that stops every script on the page and cannot be
       * styled, read by the tests, or dismissed by anything but a human.
       * Pressing once arms it and pressing again does it; anything else on the
       * sheet redraws and disarms it, which is the behaviour you want from a
       * confirmation you have wandered away from.
       *
       * Not offered for a companion. `character/retire` refuses them — they
       * are dismissed at camp, which is a scene and not a button — so the
       * offer would be a promise the server breaks.
       */
      const retire = c.companion_key
        ? ''
        : `<button type="button" class="btn btn-small retire-btn${
             state.arming === Number(c.id) ? ' is-armed' : ''}"
                   data-retire="${esc(c.id)}"
                   title="Retire ${esc(c.name)}. They leave the list; the party and everything they were carrying stay.">${
             state.arming === Number(c.id) ? 'Really retire?' : 'Retire'}</button>`;

      /*
       * Camp is the other half of a fight, and this is where the fight now
       * ends: a party back from a skirmish at two hit points has nowhere on
       * this page to sleep it off, and walking into the game to press the same
       * button is the walk the fight button exists to skip.
       *
       * Offered only where they may actually sleep. `can_camp` is the engine's
       * own answer to the question location/camp asks when this is pressed, so
       * the button and the route cannot disagree; where they may not sleep the
       * reason is said, rather than the button quietly going missing.
       *
       * Built here rather than inline below, because this comment is full of
       * the sort of prose that wants backticks in it and a backtick inside a
       * template literal ends the template literal.
       */
      const camp = ctx.can_camp
        ? `<button type="button" class="btn btn-small camp-btn"
                   data-party="${esc(ctx.party_id)}"
                   title="A long rest: wounds close, spells return, hit dice come back. Free where the party is standing.">
             Make camp
           </button>`
        : '<span class="camp-no" title="Camping needs open ground or an inn.">No camp here</span>';

      /*
       * The party, across the top of their own sheet. See partyTabs().
       *
       * The rail on the right already lists everybody, but it lists them by
       * ADVENTURE — every character on the account, in groups — and comparing
       * two people in the same party means finding them among the rest of
       * them. These are the four (or one) who march together, above the buttons
       * that act on all of them, and they are the fastest thing on the page:
       * every sheet is fetched once and kept, and the others are already on
       * their way before you press anything (see prefetchParty).
       *
       * The roster comes from the server with the sheet. It was filtered out
       * of `session/list` at first, which was cheaper and wrong: that list is
       * the characters you may PLAY and has no companions in it by design, so
       * a party of three and Brother Aldric drew three tabs and quietly lost
       * the fourth. `session/sheet` ships the party as the game holds it —
       * slot order, companions among them and marked as such — and reading a
       * companion's sheet is allowed for the same reason the tab is drawn.
       *
       * Not drawn at all for a party of one, where a row of tabs is a control
       * with nothing to choose.
       */
      const party = payload.party || [];
      /*
       * Each tab is a tick and a door, and they are two controls rather than
       * one: a checkbox nested inside a button is not valid HTML and, more to
       * the point, "bring them" and "show me them" are different questions
       * about the same person. The tick decides who walks out when a fight is
       * asked for; the rest of the tab opens their sheet.
       *
       * Somebody at nought hit points cannot be ticked. The engine drops them
       * from the field anyway, so a tick that could be set and then ignored
       * would be a promise the fight does not keep.
       */
      const tabs = partyTabs(party, c.id, ctx.party_id, true);

      const play = ctx.party_id
        ? `<div class="play-doors">
             <button type="button" class="btn btn-primary btn-lg play-btn"
                     data-party="${esc(ctx.party_id)}">
               <svg aria-hidden="true"><use href="#i-play"></use></svg>
               Play ${esc(ctx.module_name || 'this adventure')}
             </button>
           </div>
           ${where ? `<p class="play-where">${esc(where)}</p>` : ''}
           <div class="fight-row">
             <span class="fight-label">
               <svg aria-hidden="true"><use href="#i-swords"></use></svg>
               Random encounter
             </span>
             ${(payload.tiers || []).map((t) => `
               <button type="button" class="btn btn-small fight-btn"
                       data-party="${esc(ctx.party_id)}" data-tier="${esc(t.tier)}"
                       title="${esc(t.blurb)} Built to this party's size and level, and worth exactly what the same monsters are worth anywhere else.">
                 ${esc(t.label)}
               </button>`).join('')}
             ${camp}
           </div>`
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
                 href="sheet_print.php?character_id=${esc(c.id)}&amp;print=1&amp;from=picker"
                 title="A paper sheet for the table, in the standard 5e layout. Opens in a new tab.">Print</a>
              <button type="button" class="btn btn-small" data-open="bag"
                      data-id="${esc(c.id)}"
                      title="What ${esc(c.name)} is carrying, and what they are wearing">Bag</button>
              <button type="button" class="btn btn-small" data-open="store"
                      data-id="${esc(c.id)}"
                      title="Buy supplies and sell what you do not want. Nothing enchanted beyond +1.">Store</button>
              ${retire}
            </div>
          </div>

          ${tabs}

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
    /**
     * One character's sheet, fetched once.
     *
     * Everything that wants a sheet goes through here, so a payload is asked
     * for at most once however many ways there are to open it — the rail, a
     * party tab, or the page landing on somebody. `fetching` is the guard that
     * makes that true while a request is still in the air: without it, opening
     * a sheet and having its party prefetched a moment later asks for the same
     * character twice.
     */
    async function sheetFor(id) {
      if (state.sheets.has(id)) return state.sheets.get(id);
      if (state.fetching.has(id)) return null;    // already coming; whoever asked first will draw it
      state.fetching.add(id);
      try {
        const payload = await API.get('session/sheet', { character_id: id });
        state.sheets.set(id, payload);
        return payload;
      } finally {
        state.fetching.delete(id);
      }
    }

    /**
     * The rest of the party, before they are asked for.
     *
     * The point of the tabs is that pressing one is instant, and it is only
     * instant if the sheet is already in hand — the first press would otherwise
     * pay for a fetch that could have happened while the player was reading the
     * sheet in front of them. Fired and not awaited: nothing on screen is
     * waiting for it, and a failure here costs a fetch later rather than
     * anything visible now.
     *
     * Cheap by construction. A party holds at most four, they are asked for
     * once each per page load, and a sheet cannot go stale while it is being
     * looked at because nothing on this page changes a character — except camp,
     * which drops the whole cache itself.
     */
    function prefetchParty(payload) {
      (payload?.party || []).forEach((m) => {
        if (!state.sheets.has(Number(m.id))) sheetFor(Number(m.id)).catch(() => {});
      });
    }

    /**
     * Show a character, in whatever the pane is currently showing.
     *
     * The party strip keeps you where you are (`keep`) and the rail down the
     * right does not: pressing a name in the strip means "the same thing, for
     * them" — their bag, if you are in a bag — and pressing one in the rail
     * means "show me somebody else", which lands on a sheet. Two different
     * questions that would otherwise need two different-looking controls.
     */
    async function openCharacter(id, opts) {
      const keep = !!(opts && opts.keep);
      if (state.arming !== null && state.arming !== id) state.arming = null;
      if (!keep) state.view = 'sheet';
      state.selected = id;
      if (state.view === 'bag') {
        renderRail();
        return renderBag(id);
      }
      if (state.view === 'store') {
        renderRail();
        return renderStore(id);
      }
      return showSheet(id, opts && opts.pressed);
    }

    async function showSheet(id, pressed) {
      // Looking at somebody else disarms a Retire you walked away from. Only
      // when it is somebody else: the first press re-renders this same sheet to
      // put "Really retire?" on the button, and that must not undo itself.
      if (state.arming !== null && state.arming !== id) state.arming = null;
      state.view = 'sheet';
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
        const payload = state.sheets.get(id);
        renderSheet(payload);
        // Even from cache: the party this one belongs to may not have been
        // seen yet, and the tabs it just drew are only fast if the sheets
        // behind them are already coming.
        prefetchParty(payload);
        return;
      }
      host.innerHTML = '<p class="help-hint">Reading the sheet…</p>';
      try {
        const payload = await sheetFor(id);
        // Null means somebody else's request is already in the air for this
        // id; it will land in the cache, and the click that started it is the
        // one that draws.
        if (payload && state.selected === id) {
          renderSheet(payload);
          prefetchParty(payload);
        }
      } catch (e) {
        host.innerHTML = `<p class="help-hint">Could not read that sheet.</p>`;
        showError(e.message);
      }
    }

    function showError(message) {
      const banner = document.getElementById('error-banner');
      banner.textContent = message || 'Something went wrong.';
      banner.classList.remove('is-good');
      banner.classList.remove('hidden');
    }

    /**
     * What just happened, when what just happened went well.
     *
     * The same strip as the errors, in the other colour. Camp is the one thing
     * on this page that changes the world and shows nothing for it otherwise —
     * hit points move in the rail, which is easy to miss, and a companion who
     * has had enough leaves at the fire and must not do so silently.
     */
    function showNotice(message) {
      const banner = document.getElementById('error-banner');
      banner.textContent = message;
      banner.classList.add('is-good');
      banner.classList.remove('hidden');
    }

    // =====================================================================
    // Wiring
    // =====================================================================

    document.getElementById('char-list').addEventListener('click', (e) => {
      const btn = e.target.closest('[data-pick]');
      if (btn) openCharacter(Number(btn.dataset.pick), { pressed: true });
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
    // =====================================================================
    // The bag and the store
    //
    // Two panels that take over the detail pane rather than opening on top of
    // it. This page has no modal machinery of its own — the game's lives in
    // ui-panels.js, behind the whole game shell — and a pane that swaps what it
    // is showing is both less code and less to explain: the rail stays where it
    // is, the character stays selected, and Back puts the sheet up again.
    //
    // Both act on the character whose sheet is open, which is why every
    // inventory route now takes a `character_id`: the session is playing
    // somebody else, or nobody.
    // =====================================================================

    /** The pane's own header: who this is about, and the way back. */
    function panelHead(name, title) {
      return `<div class="panel-head">
        <button type="button" class="btn btn-small" data-open="sheet">&larr; Back</button>
        <h2>${esc(title)}</h2>
        <span class="panel-who">${esc(name)}</span>
      </div>`;
    }

    /**
     * What they are carrying.
     *
     * Equipping is the point of it — the sheet says what is worn and this is
     * where that is decided. `inventory/equip` is the game's own route and
     * decides everything: which slot a thing occupies, whether it can be worn
     * at all, and what comes off when a hand or a finger is already full.
     *
     * Potions are drunk here too. That is not scope creep: a party standing on
     * the front page between fights is exactly who wants to drink one, and
     * `inventory/use` was already the route for it.
     */
    async function renderBag(id) {
      const host = document.getElementById('detail');
      host.innerHTML = '<p class="help-hint">Reading the pack…</p>';

      // The sheet's payload as well as the pack, for the two things a bag needs
      // that a list of items does not carry: whose it is, and who else is in
      // the party. Cached almost always — the prefetch has been round for every
      // member — so this is one request in practice rather than two.
      let payload;
      let items;
      try {
        [payload, items] = await Promise.all([
          sheetFor(id),
          API.get('inventory/list', { character_id: id }).then((r) => r.inventory || []),
        ]);
      } catch (e) {
        showError(e.message);
        return;
      }
      if (state.view !== 'bag' || state.selected !== id) return;

      const who = payload?.sheet?.character?.name || 'This character';
      const party = payload?.party || [];
      const others = party.filter((m) => Number(m.id) !== Number(id));

      /*
       * Handing things over.
       *
       * One target for the whole panel rather than a picker on every row: a
       * party holds four, the answer is the same for the six things you are
       * about to move, and a select per row is a control repeated twenty times
       * to ask a question once. "Hand to X", then press Give down the list.
       *
       * `inventory/give` does the rest and is the game's own route: it refuses
       * anybody outside the party, takes the thing off first if it was being
       * worn, and merges the stack into one the receiver already has.
       */
      const handTo = others.length
        ? `<label class="hand-to">Hand things to
             <select id="give-to">
               ${others.map((m) => `<option value="${esc(m.id)}">${esc(m.name)}</option>`).join('')}
             </select>
           </label>`
        : '';

      const rows = items.map((it) => {
        const worn = Number(it.is_equipped) === 1;
        const potion = it.item_type === 'potion';
        return `<li class="bag-row${worn ? ' is-worn' : ''}">
          <span class="bag-name">${esc(it.name)}${it.quantity > 1
            ? ` <span class="stat-note">×${esc(it.quantity)}</span>` : ''}</span>
          <span class="stat-note">${esc(it.item_type)}${it.damage_dice
            ? ' · ' + esc(it.damage_dice) + ' ' + esc(it.damage_type || '') : ''}${it.armor_bonus
            ? ' · AC ' + esc(it.armor_bonus) : ''}</span>
          <span class="bag-acts">
            ${potion
              ? `<button type="button" class="btn btn-small" data-use="${esc(it.id)}">Drink</button>`
              : ''}
            ${it.equip_slot
              ? `<button type="button" class="btn btn-small" data-equip="${esc(it.id)}"
                         data-on="${worn ? '0' : '1'}">${worn ? 'Take off' : 'Equip'}</button>`
              : ''}
            ${others.length
              ? `<button type="button" class="btn btn-small" data-give="${esc(it.id)}"
                         title="Hand it to whoever is named above">Give</button>`
              : ''}
          </span>
        </li>`;
      }).join('');

      host.innerHTML = panelHead(who, 'The bag')
        + partyTabs(party, id, payload?.context?.party_id, false)
        + handTo
        + (items.length
          ? `<ul class="bag-list">${rows}</ul>`
          : '<p class="help-hint">Carrying nothing at all.</p>');
    }

    /**
     * The general store.
     *
     * One counter, no shopkeeper — see GeneralStore. What is on the shelf is
     * the server's answer and not a list held here; the page draws what
     * arrives, which is how "nothing enchanted beyond +1" stays one rule in one
     * place rather than a filter here that agrees with it until somebody edits
     * an item.
     */
    async function renderStore(id) {
      const host = document.getElementById('detail');
      const who = state.sheets.get(id)?.sheet?.character?.name || 'This character';
      host.innerHTML = panelHead(who, 'The general store') + '<p class="help-hint">Opening up…</p>';
      let shop;
      try {
        shop = await API.get('inventory/store', { character_id: id });
      } catch (e) {
        showError(e.message);
        return;
      }
      if (state.view !== 'store' || state.selected !== id) return;

      const buys = (shop.items || []).map((it) => `
        <li class="bag-row">
          <span class="bag-name">${esc(it.name)}</span>
          <span class="stat-note">${esc(it.item_type)}${it.rarity && it.rarity !== 'common'
            ? ' · ' + esc(it.rarity) : ''}</span>
          <span class="bag-acts">
            <span class="coin">${esc(it.price)} gp</span>
            <button type="button" class="btn btn-small" data-buy="${esc(it.id)}"
                    ${Number(it.price) > Number(shop.gold) ? 'disabled title="Not enough gold"' : ''}>Buy</button>
          </span>
        </li>`).join('');

      const sells = (shop.sellables || []).map((it) => `
        <li class="bag-row">
          <span class="bag-name">${esc(it.name)}${it.quantity > 1
            ? ` <span class="stat-note">×${esc(it.quantity)}</span>` : ''}</span>
          <span class="stat-note">${esc(it.item_type)}</span>
          <span class="bag-acts">
            <span class="coin">${esc(it.sale_price)} gp</span>
            <button type="button" class="btn btn-small" data-sell="${esc(it.inventory_id)}">Sell</button>
          </span>
        </li>`).join('');

      host.innerHTML = panelHead(who, 'The general store') + `
        <p class="store-purse">Purse: <strong>${esc(shop.gold)} gp</strong>
          <span class="stat-note">Half price when you sell. Nothing here is enchanted beyond +1.</span></p>
        <div class="sheet-cols">
          <div class="sheet-section">
            <h3>For sale</h3>
            <ul class="bag-list">${buys || '<li class="help-hint">The shelf is empty.</li>'}</ul>
          </div>
          <div class="sheet-section">
            <h3>They will buy</h3>
            <ul class="bag-list">${sells || '<li class="help-hint">Nothing of yours is worth selling.</li>'}</ul>
          </div>
        </div>`;
    }

    /**
     * Anything that changed a character from one of these panels.
     *
     * The sheet cached for them is now wrong — gold, what is worn, what the
     * armour class is — so it goes, and the rail with it. The panel is drawn
     * again rather than left as it was, because the prices, the purse and the
     * Equip/Take off labels are all answers that just changed.
     */
    async function afterTrade(id, message) {
      state.sheets.delete(id);
      try {
        state.characters = (await API.get('session/list')).characters || [];
      } catch (e) { /* the rail keeps what it had */ }
      renderRail();
      if (state.view === 'bag') await renderBag(id);
      else if (state.view === 'store') await renderStore(id);
      if (message) showNotice(message);
    }

    /**
     * Retire somebody, on the second press.
     *
     * The route is the game's own: it refuses the last character anywhere, it
     * refuses companions, and it moves the session off whoever it just retired
     * so the next request does not load a character that is gone. All this does
     * is ask, and then read the world again — the rail has one fewer name in it
     * and the sheet on screen is of somebody who is no longer there, so it
     * lands on whoever is left.
     */
    async function retireCharacter(btn) {
      const id = Number(btn.dataset.retire);
      if (state.arming !== id) {
        // First press: arm it, and say so on the button itself.
        state.arming = id;
        await showSheet(id);
        return;
      }
      state.arming = null;
      btn.disabled = true;
      try {
        const r = await API.post('character/retire', { character_id: id });
        state.sheets.delete(id);
        state.characters = (await API.get('session/list')).characters || [];
        // The party's marching order has changed, so any sheet that draws tabs
        // for it is now drawing a member who has gone.
        state.sheets.clear();
        state.joining.clear();
        renderRail();
        const next = state.characters[0];
        if (next) {
          await showSheet(Number(next.id));
        } else {
          renderShelf('Start your first adventure');
        }
        showNotice(r.message || 'Retired.');
      } catch (err) {
        btn.disabled = false;
        showError(err.message || 'They could not be retired.');
      }
    }

    /**
     * A night's sleep, from here.
     *
     * `location/camp` is the game's own route and the rules stay in it: it
     * refuses where camping is not allowed, it heals everyone at the fire
     * including the companions who are waiting there, it gives the hit dice
     * back, and it is where a companion whose approval has bottomed out walks
     * away. All this page does is make the party active first — the route
     * answers for the session, exactly as it does in the game — and then read
     * the world again, because a long rest changes every character in the
     * party and the sheet on screen is one of them.
     */
    async function makeCamp(btn) {
      btn.disabled = true;
      try {
        await API.post('session/select', { party_id: Number(btn.dataset.party) });
        const r = await API.post('location/camp', {});
        // Every cached sheet, not this one: a long rest heals the whole party,
        // so any of them that is looked at next would be drawn from a copy
        // taken before the fire.
        state.sheets.clear();
        try {
          state.characters = (await API.get('session/list')).characters || [];
        } catch (e) { /* the rail keeps what it had; the sheet still reloads */ }
        renderRail();
        await showSheet(state.selected);
        showNotice([r.message, ...(r.messages || [])].filter(Boolean).join(' '));
      } catch (err) {
        showError(err.message || 'They could not make camp here.');
      } finally {
        btn.disabled = false;
      }
    }

    document.getElementById('detail').addEventListener('click', async (e) => {
      const camp = e.target.closest('.camp-btn');
      if (camp) {
        await makeCamp(camp);
        return;
      }
      // The bag, the store, and the way back to the sheet.
      const open = e.target.closest('[data-open]');
      if (open) {
        state.view = open.dataset.open;
        const id = Number(open.dataset.id) || state.selected;
        if (state.view === 'bag') await renderBag(id);
        else if (state.view === 'store') await renderStore(id);
        else await showSheet(id);   // Back: the sheet, and the view with it
        return;
      }

      const equip = e.target.closest('[data-equip]');
      if (equip) {
        equip.disabled = true;
        try {
          const on = equip.dataset.on === '1';
          // The row's own name, before the panel is redrawn out from under it.
          const what = equip.closest('.bag-row')?.querySelector('.bag-name')?.textContent?.trim();
          await API.post('inventory/equip', {
            character_id: state.selected,
            inventory_id: Number(equip.dataset.equip),
            equip: on,
          });
          // `equip` answers with the pack and the character rather than a
          // sentence, so the sentence is written here out of what was asked
          // for. The alternative is a server that words this for one screen
          // and is quoted by another that words it differently.
          await afterTrade(state.selected,
            what ? `${what} ${on ? 'equipped' : 'taken off'}.` : null);
        } catch (err) {
          equip.disabled = false;
          showError(err.message || 'That could not be worn.');
        }
        return;
      }

      const drink = e.target.closest('[data-use]');
      if (drink) {
        drink.disabled = true;
        try {
          const r = await API.post('inventory/use', {
            character_id: state.selected,
            inventory_id: Number(drink.dataset.use),
          });
          await afterTrade(state.selected, r.message);
        } catch (err) {
          drink.disabled = false;
          showError(err.message || 'That could not be drunk.');
        }
        return;
      }

      const gift = e.target.closest('[data-give]');
      if (gift) {
        const to = document.getElementById('give-to');
        if (!to || !to.value) return;
        gift.disabled = true;
        try {
          const r = await API.post('inventory/give', {
            character_id: state.selected,
            inventory_id: Number(gift.dataset.give),
            to_character_id: Number(to.value),
          });
          // Same as equipping: the route answers with what moved and to whom,
          // and the sentence is this page's to write.
          await afterTrade(state.selected, `${esc(r.item)}${r.moved > 1 ? ' ×' + r.moved : ''}`
            + ` handed to ${esc(r.recipient?.name || 'them')}.`);
        } catch (err) {
          gift.disabled = false;
          showError(err.message || 'That could not be handed over.');
        }
        return;
      }

      const buy = e.target.closest('[data-buy]');
      if (buy) {
        buy.disabled = true;
        try {
          const r = await API.post('inventory/buy', {
            character_id: state.selected,
            item_id: Number(buy.dataset.buy),
            npc_key: '_general_store',
          });
          await afterTrade(state.selected, r.message);
        } catch (err) {
          buy.disabled = false;
          showError(err.message || 'That could not be bought.');
        }
        return;
      }

      const sell = e.target.closest('[data-sell]');
      if (sell) {
        sell.disabled = true;
        try {
          const r = await API.post('inventory/sell', {
            character_id: state.selected,
            inventory_id: Number(sell.dataset.sell),
          });
          await afterTrade(state.selected, r.message);
        } catch (err) {
          sell.disabled = false;
          showError(err.message || 'That could not be sold.');
        }
        return;
      }

      const retire = e.target.closest('[data-retire]');
      if (retire) {
        await retireCharacter(retire);
        return;
      }
      // A tick on a tab: who comes to the next fight. Read off the box rather
      // than toggled here, so the checkbox remains the thing that holds the
      // state and a keyboard press needs no second code path.
      const join = e.target.closest('[data-join]');
      if (join) {
        const party = state.sheets.get(state.selected)?.context?.party_id;
        const set = party ? state.joining.get(party) : null;
        if (set) {
          if (join.checked) set.add(Number(join.dataset.join));
          else set.delete(Number(join.dataset.join));
        }
        return;   // no redraw: a tick is not a change of view
      }
      // A party tab: same door as a name in the rail, so the same handler
      // answers it and the rail's highlight moves with the sheet.
      const tab = e.target.closest('[data-pick]');
      if (tab) {
        openCharacter(Number(tab.dataset.pick), { keep: tab.dataset.keep === '1' });
        return;
      }
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
        if (fight) {
          const r = await API.post('combat/random', {
            tier: btn.dataset.tier || 'fair',
            // Who was ticked. Sent for a party of one as well, where there are
            // no tabs and the set is simply that one person — the route takes
            // the same shape either way rather than the page having two ways
            // of asking for a fight.
            members: [...(state.joining.get(Number(btn.dataset.party)) || [])],
          });
          /*
           * Which fight to come back from.
           *
           * A skirmish is a sortie from this page and the player is returned
           * here when it ends, which the game has to be told because the fight
           * itself is an ordinary one — same engine, same board, same payout —
           * and nothing about it says where it was arranged.
           *
           * The session's OWN id, not a bare "came from the picker" flag: the
           * flag would still be sitting there an hour later when some authored
           * fight ended and would bounce the player out of their game. Matched
           * against the fight that is ending, it can only ever fire once, for
           * the fight it was written for.
           *
           * sessionStorage rather than the server, because this is where a
           * browser tab is going next — not a fact about the playthrough. It
           * belongs to the tab, survives a reload of it, and does not follow
           * the save into a window that never pressed this button.
           */
          sessionStorage.setItem('rpg:picker-fight', String(r.combat?.id ?? ''));
        }
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
