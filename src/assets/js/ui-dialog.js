/**
 * The dialogue theatre.
 *
 * A conversation takes the screen: the scene dims and a panel comes up with the
 * speaker's portrait beside their line. It used to be a strip along the bottom
 * with the world still lit above it, on the reasoning that a conversation
 * happens somewhere and covering that somewhere throws it away. That held while
 * the somewhere was a drawn map; in a text game it is a paragraph the player has
 * already read, and the strip was competing with it for the same eye.
 *
 * A node is PLAYED rather than printed. The speaker's line types out; then any
 * companion with something to say takes the panel in turn, with their own face
 * and their own name, behind a Next button. Only when everyone has spoken do the
 * replies appear. Space finishes the line that is typing, or moves to whoever is
 * next — one key, because a text crawl you cannot hurry is a text crawl people
 * learn to resent.
 *
 * Two dialogue formats reach this file and both are drawn the same way:
 *
 *   - The branching engine, which the client drives through `dialogue/node`,
 *     `dialogue/choose` and `dialogue/resolve_check`. Conditions, checks and
 *     effects all live on the server; the browser is handed a line, a list of
 *     replies it is allowed to give, and an index to send back.
 *   - The seeded trees in `npcs.dialogue_json`, which are a flat map of
 *     `{text, choices:[{label, next|action}]}` with no `nodes` wrapper. The
 *     innkeeper, the job board and the merchant are still these, and
 *     `dialogue/act` is still what answers them.
 *
 * Telling them apart is one check for `dialogue.nodes`. Rendering them
 * differently would mean two dialogue screens to keep in step, which is how
 * the seeded NPCs would quietly stop matching the authored ones.
 */
(function () {
  const G = () => window.Game;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => window.Game.esc(s);

  let session = null; // { npc, release, resolveClosed }

  function root() {
    return $('#dialogue-root');
  }

  /**
   * Start talking to somebody.
   *
   * @param npc a row from `dialogue/npc`: id, npc_key, name, role, image_url,
   *            description and the decoded `dialogue` tree.
   * @returns {Promise<void>} resolves when the conversation closes
   */
  function open(npc) {
    if (session) close({ handoff: true });
    return new Promise((done) => {
      const el = root();
      el.classList.remove('hidden');
      // Marks the body so the HUD's action bar stands down rather than
      // showing through behind the conversation.
      document.body.classList.add('in-dialogue');
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-modal', 'true');
      el.setAttribute('aria-label', 'Conversation with ' + (npc.name || 'someone'));
      el.innerHTML = '<div class="dlg-loading help-hint">…</div>';

      session = { npc, release: null, done, closing: false };
      session.release = window.UI.pushOverlay(el, () => close());

      document.addEventListener('keydown', onNumberKey, true);
      el.addEventListener('click', onPanelClick);

      const modern = npc.npc_key && npc.dialogue && npc.dialogue.nodes;
      const first = modern
        ? gotoNode(npc.npc_key, null)
        : Promise.resolve(showLegacyNode(npc, legacyStartKey(npc)));

      first.catch((e) => {
        G().showError(e.message);
        close();
      });
    });
  }

  /**
   * Open the theatre straight at a named node.
   *
   * Two callers. A fight becomes a conversation this way — `parley_node` is
   * authored as "npc_key:node_key" and there is no NPC to walk up to, because
   * the party is standing in the middle of a battle they just talked their way
   * out of. And the camp screen opens a companion's `camp` node when you sit
   * down with them.
   *
   * `opts.camp` names the companion whose campfire scene should back the
   * conversation: the difference between talking to a portrait and sitting
   * with somebody at a fire.
   *
   * `opts.fromCamp` is the OTHER half of that, and they are separate because
   * the camp screen has two doors. The seat sits you down at the fire, which
   * both paints the scene and owes you a way back to it. "Talk" draws the
   * companion aside for their whole tree — no fireside backdrop, its own
   * button says as much — and it was returning you to the location instead of
   * the fire, because the only thing that had ever asked for the fire back was
   * the scene key. One flag meant two things, so the door that wanted only one
   * of them got neither.
   *
   * @returns {Promise<void>} resolves when the conversation closes
   */
  function openNode(npcKey, nodeKey, opts = {}) {
    if (session) close({ handoff: true });
    return new Promise((done) => {
      const el = root();
      el.classList.remove('hidden');
      // Marks the body so the HUD's action bar stands down rather than
      // showing through behind the conversation.
      document.body.classList.add('in-dialogue');
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-modal', 'true');
      el.setAttribute('aria-label', 'Conversation');
      el.innerHTML = '<div class="dlg-loading help-hint">…</div>';

      session = {
        npc: { npc_key: npcKey }, release: null, done, closing: false,
        camp: opts.camp || null,
        // Where this conversation came from, as opposed to how it is dressed.
        fromCamp: !!(opts.camp || opts.fromCamp),
      };
      session.release = window.UI.pushOverlay(el, () => close());
      document.addEventListener('keydown', onNumberKey, true);
      el.addEventListener('click', onPanelClick);

      gotoNode(npcKey, nodeKey).catch((e) => {
        // A companion with no `camp` node authored yet, or none whose
        // conditions this party matches, is not an error the player caused —
        // they sat down and the person had nothing to say tonight. Say that,
        // in the log, rather than showing them the engine's own words in a
        // red banner.
        if (opts.camp) {
          G().log(`${opts.name || 'They'} have nothing to say tonight.`, '', 'story');
          // But say WHY somewhere a developer can read it. This catch is a
          // catch-all: a missing node, a network failure, a 500 and a genuine
          // "nothing matched tonight" all arrive here and all used to produce
          // the same calm sentence. That made "I can't talk to them" a report
          // with nothing attached to it — the one thing that would identify the
          // cause was being discarded at the moment it was caught.
          console.warn(`camp conversation with ${npcKey} failed:`, e);
        } else {
          G().showError(e.message);
        }
        close();
      });
    });
  }

  /**
   * @param {{handoff?: boolean}} [opts] `handoff` when the conversation is
   *        closing to make room for something that is not a conversation —
   *        a fight, a shop, a journey. Those replace the screen, so a camp
   *        conversation must not reopen the fire on top of them.
   */
  function close(opts = {}) {
    if (!session || session.closing) return;
    session.closing = true;
    // Stop a half-typed line before the element it is writing into is gone.
    if (session.skip) session.skip = null;
    document.removeEventListener('keydown', onNumberKey, true);
    const el = root();
    el.removeEventListener('click', onPanelClick);
    el.classList.add('hidden');
    document.body.classList.remove('in-dialogue');
    el.innerHTML = '';
    if (session.release) session.release();
    const done = session.done;
    const fromCamp = session.fromCamp;
    session = null;
    if (done) done();

    // A conversation can pay XP — a quest turned in, a reward node — and it
    // cannot stop mid-node to ask which ability score somebody would like. So
    // the level waits here, for the moment the talking is over.
    if (window.LevelUp) window.LevelUp.checkPending();

    if (fromCamp && !opts.handoff) returnToCamp();
  }

  /**
   * Back to the fire.
   *
   * Sitting with somebody is a moment inside the camp screen, not instead of
   * it — openCamp closes itself on the way in only so the conversation has the
   * modal stack to itself. Without this, "Get some sleep" and every other
   * `end: true` reply put the player back out in the location, which reads as
   * the night being over when they only meant to stop talking to one person.
   *
   * Refreshed first because the talk usually moved approval, and the seats draw
   * it: reopening on the state we walked in with would show the old mood.
   */
  function returnToCamp() {
    if (!window.UI || !window.UI.openCamp) return;
    Promise.resolve(G().refreshAll()).then(() => window.UI.openCamp());
  }

  /**
   * Number keys pick replies, as they have since the first CRPG did it.
   *
   * Bound on the document in the capture phase and gated on the theatre being
   * open, rather than on each button: a reply you can only reach by tabbing to
   * it first is not a shortcut.
   */
  function onNumberKey(e) {
    if (!session || e.ctrlKey || e.altKey || e.metaKey) return;
    if (G().isTypingTarget(e.target)) return;

    // Space and Enter drive the scene: finish the line that is typing, or move
    // to whoever speaks next. Enter is left alone once the replies are up, so
    // it still activates the focused choice.
    if (e.key === ' ' || (e.key === 'Enter' && session.skip)) {
      if (advance()) {
        e.preventDefault();
        e.stopPropagation();
      }
      return;
    }

    if (!/^[1-9]$/.test(e.key)) return;
    const buttons = $$('.dlg-choice', root());
    const btn = buttons[parseInt(e.key, 10) - 1];
    if (!btn || btn.disabled) return;
    e.preventDefault();
    e.stopPropagation();
    btn.click();
  }

  /**
   * Clicking the panel while a line is typing finishes it.
   *
   * Bound on the root once per session rather than per beat. Clicks on a
   * button are left alone — a player reaching for Next has already said what
   * they want, and swallowing that click to finish a line they can already
   * read would cost them the press.
   */
  function onPanelClick(e) {
    if (!session || !session.skip) return;
    if (e.target.closest('button')) return;
    session.skip();
  }

  // =========================================================================
  // The branching engine
  // =========================================================================

  async function gotoNode(npcKey, nodeKey) {
    const r = await API.post('dialogue/node', { npc_key: npcKey, node_key: nodeKey });
    renderNode(r.node);
  }

  /**
   * A node is played as a sequence of beats, not printed as a block.
   *
   * The NPC speaks first; then each companion who has something to say takes
   * the panel in turn, with their own face and their own name. Only when
   * everyone has spoken do the replies appear.
   *
   * This is the difference between reading a page and being in a scene. The
   * asides used to sit under the speaker's line as small grey paragraphs,
   * where they were easy to miss entirely — which is a shame, because a
   * companion cutting in is the game's best trick for making the party feel
   * like people rather than a stat block with four portraits.
   */
  function renderNode(node) {
    if (!session) return;
    session.node = node;
    session.beats = buildBeats(node);
    session.beat = 0;
    renderShell();
    playBeat();
  }

  /** The speaker's line, then one beat per companion aside. */
  function buildBeats(node) {
    const beats = [{
      name: node.speaker?.name || '',
      role: node.speaker?.role || '',
      bust: G().withTileVer(node.speaker?.bust || ''),
      fallback: G().bodySpriteUrl(node.speaker?.image_url || ''),
      text: node.text || '',
    }];

    (node.interjections || []).forEach((i) => {
      const row = companionRow(i.companion);
      const band = window.UI.approvalBand(i.approval || 0);
      beats.push({
        name: row ? row.name : i.companion,
        role: row ? (row.title || row.class || '') : '',
        // Companions wear NPC art, so their bust is the same set the dialogue
        // theatre already draws for anybody else.
        bust: row && row.sprite_key ? G().spriteArtUrl(row.sprite_key, '_bust') : '',
        fallback: '',
        text: stripSpeakerPrefix(i.text || '', row ? row.name : i.companion, i.companion),
        aside: true,
        band: band && band.cls ? band.cls : '',
      });
    });

    return beats;
  }

  /** The frame the beats play inside. Built once per node. */
  function renderShell() {
    // At camp the scene sits behind the panel: the same fire, whoever you are
    // sitting with. It is decoration and it is optional — a companion with no
    // camp art generated yet simply gets the ordinary panel, and nothing here
    // has to know which of them have one.
    const camp = session.camp
      ? `<img class="dlg-camp-scene" alt="" aria-hidden="true"
              src="assets/images/camp/${encodeURIComponent(session.camp)}.png"
              onerror="this.remove()">`
      : '';
    root().innerHTML = `
      <div class="dlg-panel${session.camp ? ' is-camp' : ''}" role="document">
        ${camp}
        <div class="dlg-speaker">
          <img class="dlg-bust" alt="">
          <div class="dlg-name"></div>
          <div class="dlg-role"></div>
        </div>
        <div class="dlg-script">
          <p class="dlg-line" aria-live="polite"></p>
          <div class="dlg-advance" id="dlg-advance"></div>
          <div class="dlg-choices" id="dlg-choices"></div>
        </div>
        <button type="button" class="dlg-close btn btn-small" data-close
                aria-label="End the conversation">Esc</button>
      </div>`;
    root().querySelector('[data-close]').onclick = () => close();
  }

  /**
   * Show one beat: swap the portrait and the name, then type the line.
   *
   * Clicking anywhere in the panel while it is typing finishes the line at
   * once — the convention every game with a text crawl uses, and the reason a
   * crawl is tolerable to somebody who reads faster than it types.
   */
  function playBeat() {
    if (!session) return;
    const beat = session.beats[session.beat];
    if (!beat) return;

    const el = root();
    const bust = $('.dlg-bust', el);
    const line = $('.dlg-line', el);
    const speaker = $('.dlg-speaker', el);

    // A new face gets a moment to arrive; the same face does not flicker.
    if (bust.dataset.src !== (beat.bust || beat.fallback || '')) {
      bust.dataset.src = beat.bust || beat.fallback || '';
      bust.classList.remove('is-sprite');
      bust.onerror = () => {
        bust.onerror = null;
        if (beat.fallback) { bust.src = beat.fallback; bust.classList.add('is-sprite'); }
      };
      bust.src = beat.bust || beat.fallback || '';
      speaker.classList.remove('is-entering');
      // Reading offsetWidth restarts the animation; without it a re-added
      // class on an element that never left the DOM does nothing.
      void speaker.offsetWidth;
      speaker.classList.add('is-entering');
    }

    $('.dlg-name', el).textContent = beat.name;
    $('.dlg-role', el).textContent = beat.role;
    el.classList.toggle('is-aside', !!beat.aside);
    line.className = 'dlg-line' + (beat.aside ? ' is-aside ' + (beat.band || '') : '');

    $('#dlg-choices', el).innerHTML = '';
    $('#dlg-advance', el).innerHTML = '';

    session.skip = typeOut(line, beat.text, onBeatTyped);
  }

  /**
   * Type a line out a few characters at a time.
   *
   * Returns a function that finishes it immediately. Under
   * prefers-reduced-motion it is already finished — a text crawl is motion,
   * and somebody who has asked for less of it has asked for this too.
   */
  function typeOut(el, text, done) {
    if (G().reducedMotion()) {
      el.textContent = text;
      done();
      return () => {};
    }
    let i = 0;
    el.textContent = '';
    const timer = setInterval(() => {
      // Two characters a tick reads as speech rather than as a teletype, and
      // keeps a long paragraph under a couple of seconds.
      i += 2;
      el.textContent = text.slice(0, i);
      if (i >= text.length) {
        clearInterval(timer);
        done();
      }
    }, 16);

    return () => {
      clearInterval(timer);
      el.textContent = text;
      done();
    };
  }

  /** The line has finished typing: offer Next, or the replies. */
  function onBeatTyped() {
    if (!session) return;
    session.skip = null;
    const more = session.beat < session.beats.length - 1;
    if (more) {
      const box = $('#dlg-advance', root());
      box.innerHTML = '';
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn dlg-next';
      b.innerHTML = 'Next <span class="dlg-next-hint">space</span>';
      b.addEventListener('click', nextBeat);
      box.appendChild(b);
      b.focus();
    } else {
      showChoices();
    }
  }

  function nextBeat() {
    if (!session) return;
    session.beat += 1;
    playBeat();
  }

  /**
   * Advance the scene: finish the line if it is still typing, otherwise move
   * on. One key does both, which is what makes a crawl feel responsive rather
   * than like something you are waiting out.
   */
  function advance() {
    if (!session) return false;
    if (session.skip) { session.skip(); return true; }
    if (session.beat < session.beats.length - 1) { nextBeat(); return true; }
    return false;
  }

  function showChoices() {
    const node = session.node;
    const box = $('#dlg-choices', root());
    box.innerHTML = '';
    (node.choices || []).forEach((choice, index) => {
      box.appendChild(choiceButton(choice, index, () => onChoose(node, index)));
    });
    if (!node.choices || !node.choices.length) {
      box.appendChild(choiceButton({ label: 'Leave' }, 0, () => close()));
    }
    focusFirstChoice();
  }

  /** A companion's row from their key. */
  function companionRow(key) {
    return (G().state.companions || []).find((c) => c.companion_key === key) || null;
  }

  /**
   * Drop a `Aldric:` from the front of an aside when the portrait already says
   * who is talking.
   *
   * Rather more than half the authored interjections open with the speaker's
   * name, which was right when an aside was a small grey paragraph under
   * somebody else's line and had no other way to identify itself. Beside a
   * portrait captioned "Brother Aldric" it reads as a stutter.
   *
   * Only a prefix matching THIS speaker is removed — a line that genuinely
   * opens "Reed: ..." because Aldric is quoting the captain keeps it. The
   * content is left alone: it is correct prose either way, and rewriting 57
   * files to suit one screen's layout is how content ends up owned by a
   * stylesheet.
   */
  function stripSpeakerPrefix(text, name, key) {
    const m = /^\s*([A-Za-z' -]{2,20}):\s*/.exec(text);
    if (!m) return text;
    const said = m[1].trim().toLowerCase();
    const mine = new Set(
      String(name || '').toLowerCase().split(/\s+/).concat([String(key || '').toLowerCase()])
    );
    return mine.has(said) ? text.slice(m[0].length) : text;
  }

  /** A companion's name from their key, falling back to the key itself. */
  function companionName(key) {
    const row = (G().state.companions || []).find((c) => c.companion_key === key);
    return row ? row.name : key;
  }

  /**
   * One reply.
   *
   * The DC is on the button on purpose. A check the player only discovers
   * after committing to it is a trap; a check they can see the odds of before
   * committing is a decision, which is the whole point of the ceremony that
   * opens next.
   */
  function choiceButton(choice, index, run) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'dlg-choice btn'
      + (choice.spent ? ' is-spent' : '')
      + (choice.locked ? ' is-locked' : '');
    b.innerHTML = `
      <span class="dlg-key">${index + 1}</span>
      <span class="dlg-label">${esc(choice.label)}</span>
      ${choice.tag ? `<span class="pill pill-tag">${esc(choice.tag)}</span>` : ''}
      ${choice.check ? `<span class="pill pill-check">${esc(String(choice.check.label).toUpperCase())} DC ${esc(choice.check.dc)}</span>` : ''}
      ${choice.spent ? '<span class="pill pill-spent" title="You tried this and it did not work">tried</span>' : ''}
      ${choice.locked ? `<span class="pill pill-locked" title="This work is beyond you for now">${esc(choice.locked)}</span>` : ''}`;

    // A check already failed stays on the list, greyed. Removing it would
    // leave the player wondering what they had missed; showing it says plainly
    // that this was the line they took and it did not land.
    if (choice.spent) {
      b.disabled = true;
      b.title = 'You have already tried that, and it did not work.';
      return b;
    }

    // Work the party is too green for reads the same way, and for the same
    // reason: knowing the job is there and that you should come back is the one
    // thing dropping the reply could not tell them.
    if (choice.locked) {
      b.disabled = true;
      b.title = 'Come back at ' + String(choice.locked).toLowerCase() + '.';
      return b;
    }

    b.addEventListener('click', () => {
      $$('.dlg-choice', root()).forEach((x) => { x.disabled = true; });
      run();
    });
    return b;
  }

  function focusFirstChoice() {
    const first = $('.dlg-choice', root());
    if (first) first.focus();
  }

  /**
   * Take a reply and act on whichever of the three shapes comes back.
   *
   * DialogEngine::choose answers with exactly one of `check`, `node` or
   * `close`, and the client branches on which key is present rather than on a
   * type field — that is the contract the engine documents, and following it
   * means a new kind of reply cannot silently fall through to "close".
   */
  async function onChoose(node, index) {
    try {
      const r = await API.post('dialogue/choose', {
        npc_key: node.npc_key,
        node_key: node.node_key,
        choice: index,
      });
      await applyOutcome(r);
    } catch (e) {
      G().showError(e.message);
      // Re-enable the replies rather than leaving a dead panel: a rejected
      // choice usually means a condition changed, not that the conversation is
      // over.
      $$('.dlg-choice', root()).forEach((x) => { x.disabled = false; });
    }
  }

  /**
   * The reply's outcome, held across the ceremony.
   *
   * `dialogue/resolve_check` answers with both the roll and where the
   * conversation goes next, but the ceremony's promise only carries the roll —
   * it has to, because a combat save resolves through `check/resolve` and has
   * no conversation to continue. So the rest is parked here for the moment
   * between the die landing and the player dismissing it.
   */
  let pendingAfterCheck = null;

  async function applyOutcome(r) {
    if (r.check) {
      // The conversation is suspended on a die. Resolving through
      // `dialogue/resolve_check` rather than `check/resolve` is what lets the
      // reply continue in the same round trip — the destination nodes were
      // fixed when the check was offered, so nothing about where this goes is
      // decided after the number is known.
      const result = await window.Check.open(r.check, {
        resolve: async (checkId, boosts) => {
          const out = await API.post('dialogue/resolve_check', { check_id: checkId, boosts });
          pendingAfterCheck = out;
          return out.roll;
        },
      });
      const next = pendingAfterCheck;
      pendingAfterCheck = null;
      if (!result || !next) {
        // Walked away from the die: the check was never claimed, so the
        // conversation is exactly where it was.
        $$('.dlg-choice', root()).forEach((x) => { x.disabled = false; });
        return;
      }
      await applyOutcome(next);
      return;
    }

    reportEffects(r.result);

    if (r.node) {
      renderNode(r.node);
      return;
    }

    // close: possibly handing off to something that is not a conversation.
    const handoff = !!(r.combat || r.shop || r.travel);
    const wasCamp = !!(session && session.fromCamp);
    close({ handoff });
    if (r.combat) {
      await G().startCombat({ encounter_key: r.combat });
    } else if (r.shop) {
      // r.shop carries the merchant's npc_key from Effects::openShop — the
      // string used to be thrown away, which is why every shop was the same.
      await window.UI.openShop(r.shop);
    } else if (r.travel) {
      // Effects::travel has already moved the party in the database, so there
      // is nothing to ask the server for beyond a fresh look at the world.
      await G().mapFadeThrough(() => G().refreshAll());
    } else if (!wasCamp) {
      // A camp conversation is refreshed by returnToCamp on its way back to
      // the fire; refreshing here as well would be the same GET twice.
      await G().refreshAll();
    }
  }

  /**
   * What the effects did, in the log.
   *
   * `notes` are content problems — a shop that names no merchant, an approval
   * delta aimed at somebody who is not in the party — and they are shown rather
   * than swallowed, because the alternative is an author finding out from a
   * scene that quietly does nothing.
   */
  function reportEffects(result) {
    if (!result) return;
    (result.messages || []).forEach((m) => G().log(m, 'important'));
    (result.notes || []).forEach((n) => G().log('(' + n + ')', 'danger'));
    (result.level_ups || []).forEach((l) => G().log(`${l.name} reaches level ${l.level}!`, 'success'));
    Object.entries(result.approval || {}).forEach(([key, delta]) => {
      if (!delta) return;
      G().log(`${companionName(key)} ${delta > 0 ? 'approves' : 'disapproves'}.`,
        delta > 0 ? 'success' : 'danger');
    });
  }

  // =========================================================================
  // Seeded trees
  // =========================================================================

  /**
   * The opening node of a seeded tree.
   *
   * A seeded tree is `{"start": {text, choices}, …}` — `start` is a node, not a
   * pointer to one, which is exactly the opposite of the new format where
   * `start` is the name of the first node. Reading `dialogue.start` as a key
   * therefore hands an object to a lookup that wants a string, and the
   * innkeeper opens on "…".
   */
  function legacyStartKey(npc) {
    const s = npc.dialogue?.start;
    return typeof s === 'string' ? s : 'start';
  }

  function showLegacyNode(npc, nodeKey) {
    const tree = npc.dialogue || {};
    const node = tree[nodeKey] || { text: '…', choices: [{ label: 'Leave', action: 'close' }] };
    // Seeded NPCs predate expression sets and speaker overrides, so the bust
    // is whatever sits next to their map sprite.
    const bust = G().variantUrl(npc.image_url, '_bust') || '';

    root().innerHTML = `
      <div class="dlg-speaker">
        <img class="dlg-bust" src="${esc(bust)}" alt=""
             onerror="this.onerror=null;this.classList.add('is-sprite');this.src='${esc(G().bodySpriteUrl(npc.image_url || ''))}'">
        <div class="dlg-name">${esc(npc.name)}</div>
        ${npc.role ? `<div class="dlg-role">${esc(npc.role)}</div>` : ''}
      </div>
      <div class="dlg-script">
        <p class="dlg-line">${esc(node.text)}</p>
        ${npc.description ? `<p class="dlg-aside">${esc(npc.description)}</p>` : ''}
        <div class="dlg-choices" id="dlg-choices"></div>
      </div>
      <button type="button" class="dlg-close btn btn-small" data-close aria-label="End the conversation">Esc</button>`;

    root().querySelector('[data-close]').onclick = () => close();

    const box = $('#dlg-choices', root());
    (node.choices || []).forEach((choice, index) => {
      box.appendChild(choiceButton(choice, index, () => onLegacyChoice(npc, choice)));
    });
    focusFirstChoice();
  }

  async function onLegacyChoice(npc, choice) {
    if (choice.next) {
      showLegacyNode(npc, choice.next);
      return;
    }
    const action = choice.action || 'close';
    if (action === 'close') {
      close();
      return;
    }
    try {
      const r = await API.post('dialogue/act', {
        action,
        character_id: G().state.character.id,
        npc_id: npc.id,
      });
      if (r.action === 'job_board') {
        await window.UI.openJournal();
      } else if (r.action === 'shop') {
        await window.UI.openShop(npc.npc_key);
      } else {
        if (r.message) G().log(r.message, 'success');
        if (r.character) G().mergeCharacter(r.character);
        await G().refreshAll();
      }
      // Back to where it was said. The old client closed on any non-`next`
      // action, which meant you could not take a room and then ask a question.
      if (session) showLegacyNode(npc, legacyStartKey(npc));
    } catch (e) {
      G().showError(e.message);
      $$('.dlg-choice', root()).forEach((x) => { x.disabled = false; });
    }
  }

  window.Dialog = { open, openNode, close, renderNode };
})();
