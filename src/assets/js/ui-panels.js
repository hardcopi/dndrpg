/**
 * The furniture: the party rail, the context rail, the modal stack, and the
 * panels that open on top of them — journal, character sheet, camp, shop.
 *
 * The modal stack is the load-bearing idea here. Before it there was one
 * `#modal-root` whose innerHTML was replaced on every open, so a shop opened
 * from a conversation overwrote the conversation and closing the shop left you
 * standing in an empty room mid-sentence. A modal now pushes; closing pops back
 * to whatever it interrupted.
 *
 * Every overlay in the game — modal, dialogue theatre, d20 ceremony — registers
 * here through `pushOverlay`, so there is exactly one Escape handler and one
 * focus trap in the client rather than three that disagree.
 */
(function () {
  const G = () => window.Game;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => window.Game.esc(s);

  /* How many march, counting the character you play. Mirrors
     CompanionService::PARTY_CAP, which is the one that actually refuses. */
  const PARTY_CAP = 4;

  /** Log categories the filter chips select between. */
  let logFilter = 'all';

  // =========================================================================
  // Overlays: one Escape key, one focus trap, one idea of what is on top
  // =========================================================================

  /**
   * Every open overlay, outermost first. The last entry owns the keyboard.
   *
   * @type {Array<{el: HTMLElement, onEsc: Function, restore: Element|null}>}
   */
  const overlays = [];

  const FOCUSABLE = [
    'a[href]', 'button:not([disabled])', 'input:not([disabled])',
    'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])',
  ].join(',');

  /**
   * Claim the keyboard for an overlay.
   *
   * Returns the release function rather than exposing a matching `pop`, because
   * the two must be paired and a caller holding the release cannot forget which
   * overlay it was closing. Focus is moved inside on open and put back on
   * close: an overlay that steals focus and does not return it leaves a
   * keyboard user at the top of the document every time they close something.
   */
  function pushOverlay(el, onEsc) {
    const entry = { el, onEsc, restore: document.activeElement };
    overlays.push(entry);
    document.body.classList.add('overlay-open');
    // Focus the first control, or the panel itself if it has none yet.
    const first = el.querySelector(FOCUSABLE);
    if (first) {
      first.focus();
    } else {
      if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
      el.focus();
    }
    return function release() {
      const i = overlays.indexOf(entry);
      if (i >= 0) overlays.splice(i, 1);
      if (!overlays.length) document.body.classList.remove('overlay-open');
      if (entry.restore && document.contains(entry.restore) && entry.restore.focus) {
        entry.restore.focus();
      }
    };
  }

  function topOverlay() {
    return overlays.length ? overlays[overlays.length - 1] : null;
  }

  function overlayOpen() {
    return overlays.length > 0;
  }

  /**
   * Escape closes the top overlay; Tab cannot leave it.
   *
   * Bound in the capture phase so it runs before the map's own key handling,
   * which is what stops the party stepping north while a modal is open.
   */
  document.addEventListener('keydown', (e) => {
    const top = topOverlay();
    if (!top) return;

    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      top.onEsc();
      return;
    }

    if (e.key !== 'Tab') return;
    const items = $$(FOCUSABLE, top.el).filter((n) => n.offsetParent !== null || n === top.el);
    if (!items.length) {
      e.preventDefault();
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    // The trap only has to catch the two edges; everything between them is the
    // browser's own tab order, which is already correct.
    if (e.shiftKey && (document.activeElement === first || !top.el.contains(document.activeElement))) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }, true);

  // =========================================================================
  // The modal stack
  // =========================================================================

  /** @type {Array<{el: HTMLElement, release: Function, onClose: Function|null}>} */
  const modals = [];

  /**
   * Open a modal over whatever is already open.
   *
   * `opts.size` is 'normal' | 'wide' | 'tall'. The old stylesheet capped every
   * modal at 520px and made an exception for the character sheet at 720, which
   * is why a conversation with five replies scrolled and a list of six items
   * did not. Size is now a property of the content, named at the call site.
   *
   * `opts.slot` names a panel that only ever wants one copy of itself open.
   * Stacking is right for a shop over a conversation, but clicking along the
   * party rail was piling one character sheet on top of the next: five sheets
   * deep, the newest on top and four Escapes to get out, and because every one
   * of them matched `.sheet-title` the sheet appeared not to change at all.
   */
  function showModal(html, opts = {}) {
    const root = $('#modal-root');
    if (opts.slot && modals[modals.length - 1]?.slot === opts.slot) closeTopModal();
    const el = document.createElement('div');
    el.className = 'modal modal-' + (opts.size || 'normal');
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    if (opts.label) el.setAttribute('aria-label', opts.label);
    el.innerHTML = html;

    // Only the top modal is drawn. Burying the rest rather than removing them
    // is the whole point: the conversation underneath is still there when the
    // shop closes.
    modals.forEach((m) => m.el.classList.add('modal-buried'));
    root.appendChild(el);
    root.classList.remove('hidden');

    const release = pushOverlay(el, () => closeTopModal());
    const entry = { el, release, onClose: opts.onClose || null, slot: opts.slot || null };
    modals.push(entry);

    // Any title-bar × in this panel, wired here so no caller has to remember.
    $$('[data-modal-close]', el).forEach((b) => {
      b.addEventListener('click', () => closeTopModal());
    });

    root.onclick = (e) => {
      if (e.target === root) closeTopModal();
    };
    return el;
  }

  /**
   * A modal's title bar: the name of the thing, and the way out of it.
   *
   * The × is wired by showModal rather than by each caller, so a panel cannot
   * ship with a close button that does nothing. Escape and the backdrop still
   * close as they always did — this is the affordance for people who look for
   * one in the corner, which is most people.
   */
  function modalHead(title) {
    return `<div class="modal-head">
      <h2>${esc(title)}</h2>
      <button type="button" class="icon-btn modal-x" data-modal-close
              title="Close (Esc)" aria-label="Close">×</button>
    </div>`;
  }

  function closeTopModal() {
    const entry = modals.pop();
    if (!entry) return;
    entry.release();
    entry.el.remove();
    const next = modals[modals.length - 1];
    if (next) {
      next.el.classList.remove('modal-buried');
    } else {
      $('#modal-root').classList.add('hidden');
    }
    if (typeof entry.onClose === 'function') entry.onClose();
  }

  function closeAllModals() {
    while (modals.length) closeTopModal();
  }

  /**
   * A yes/no question, as a promise.
   *
   * Replaces the browser's own modal prompt, which blocks the event loop,
   * cannot be styled, and — the reason it had to go — steals focus in a way no
   * focus trap can undo, so the map key handler came back live underneath it.
   *
   * `body` is text and is escaped. `bodyHtml` is markup and is NOT, so it is
   * for markup the caller built itself and never for anything that came off
   * the wire — the ambush warning uses it to draw the faces of what is waiting,
   * and escapes every name it puts in there on the way.
   */
  function confirm(opts) {
    return new Promise((resolve) => {
      let answered = false;
      const done = (v) => {
        if (answered) return;
        answered = true;
        resolve(v);
      };
      showModal(`
        <h2>${esc(opts.title || 'Are you sure?')}</h2>
        <p class="modal-body">${esc(opts.body || '')}</p>
        ${opts.bodyHtml || ''}
        <div class="modal-actions">
          <button type="button" class="btn btn-primary" data-yes>${esc(opts.confirm || 'Yes')}</button>
          <button type="button" class="btn" data-no>${esc(opts.cancel || 'Cancel')}</button>
        </div>`, {
        label: opts.title || 'Confirm',
        onClose: () => done(false),
      });
      const el = modals[modals.length - 1].el;
      el.querySelector('[data-yes]').onclick = () => { done(true); closeTopModal(); };
      el.querySelector('[data-no]').onclick = () => { done(false); closeTopModal(); };
    });
  }

  // =========================================================================
  // The context rail: log, rolls, minimap
  // =========================================================================

  /**
   * Log filter chips.
   *
   * This used to also wire a three-tab rail (Log / Rolls / Map). The HUD made
   * two of those redundant — the minimap is always on screen now, and Rolls is
   * a panel off the icon bar — so only the filters are left.
   */
  function initLogControls() {
    $$('.log-filters .chip').forEach((chip) => {
      chip.addEventListener('click', () => {
        logFilter = chip.dataset.filter;
        $$('.log-filters .chip').forEach((c) => c.classList.toggle('on', c === chip));
        redrawLog();
      });
    });

    const collapse = $('#btn-log-collapse');
    if (collapse) {
      collapse.addEventListener('click', () => {
        const panel = $('#hud-log');
        if (!panel) return;
        const closed = panel.classList.toggle('collapsed');
        collapse.setAttribute('aria-expanded', String(!closed));
        const label = closed ? 'Expand log' : 'Collapse log';
        collapse.title = label;
        collapse.setAttribute('aria-label', label);
      });
    }
  }

  /**
   * Recent rolls, as a panel rather than a tab.
   *
   * `#roll-list` is created here rather than living in the page, because it only
   * exists while the panel is open — `refreshRolls()` bails when it is absent,
   * so nothing else has to know whether the panel is up.
   */
  function openRolls() {
    showModal(
      '<h2>Recent rolls</h2><div class="roll-list" id="roll-list">'
      + '<p class="help-hint">Loading…</p></div>',
      { slot: 'rolls', label: 'Recent rolls' }
    );
    refreshRolls();
  }

  function lineMatchesFilter(line) {
    if (logFilter === 'all') return true;
    return line.cat === logFilter;
  }

  function appendLogLine(line) {
    const box = $('#game-log');
    if (!box) return;
    if (!lineMatchesFilter(line)) return;
    box.appendChild(logLineEl(line));
    // The DOM has to honour the same 400-line cap state.log does (game.js).
    // It did not: the array was trimmed and the nodes were not, so a long
    // session grew the log panel without bound — and the scrollHeight read
    // below forces a synchronous layout of the whole panel on every line, so
    // each message cost more than the one before it. An afternoon of play was
    // enough to make the whole map feel slow, and the panel floats over the
    // map, so its weight is paid on every frame the map animates.
    while (box.childElementCount > 400) {
      box.removeChild(box.firstElementChild);
    }
    box.scrollTop = box.scrollHeight;
  }

  function logLineEl(line) {
    const el = document.createElement('div');
    el.className = 'line' + (line.cls ? ' ' + line.cls : '') + ' cat-' + (line.cat || 'story');
    el.textContent = line.msg;
    return el;
  }

  function redrawLog() {
    const box = $('#game-log');
    if (!box) return;
    box.innerHTML = '';
    G().state.log.filter(lineMatchesFilter).forEach((l) => box.appendChild(logLineEl(l)));
    box.scrollTop = box.scrollHeight;
  }

  /**
   * The Rolls tab, from `check/history`.
   *
   * Rendered from the same field names `check/resolve` hands back, which is
   * what CheckService::history goes out of its way to guarantee — a logged roll
   * and a roll that just landed are drawn by the same code.
   */
  async function refreshRolls() {
    const box = $('#roll-list');
    if (!box) return;
    try {
      const r = await API.get('check/history');
      const rolls = r.rolls || [];
      if (!rolls.length) {
        box.innerHTML = '<p class="help-hint">No dice thrown yet.</p>';
        return;
      }
      box.innerHTML = rolls.map(rollRowHtml).join('');
    } catch (e) {
      box.innerHTML = `<p class="help-hint">${esc(e.message)}</p>`;
    }
  }

  function rollRowHtml(r) {
    const dice = (r.rolls || []).join(' / ');
    const boosts = (r.boosts || [])
      .map((b) => `${esc(b.label)} ${window.Game.fmtMod(b.bonus)}`)
      .join(', ');
    const mode = r.die_mode && r.die_mode !== 'normal'
      ? `<span class="pill pill-${esc(r.die_mode)}">${esc(r.die_mode)}</span>` : '';
    return `<div class="roll-row outcome-${esc(r.outcome)}">
      <div class="roll-head">
        <strong>${esc(r.label)}</strong>
        <span class="roll-verdict">${esc(r.outcome)}</span>
      </div>
      <div class="roll-sum">
        <span class="roll-nat">${esc(r.natural)}</span>
        <span class="roll-math">${esc(dice)} ${window.Game.fmtMod(r.modifier)}
          = <strong>${esc(r.total)}</strong> vs DC ${esc(r.dc)}</span>
        ${mode}
      </div>
      ${r.character_name ? `<div class="roll-who">${esc(r.character_name)}</div>` : ''}
      ${boosts ? `<div class="roll-who">${esc(boosts)}</div>` : ''}
    </div>`;
  }

  // =========================================================================
  // The quest tracker
  // =========================================================================

  /** The one quest the tracker follows: the tracked one, or the oldest active. */
  function trackedQuest() {
    const mine = G().state.quests || [];
    return mine.find((q) => +q.is_tracked === 1 && q.status === 'active')
      || mine.find((q) => q.status === 'active')
      || null;
  }

  /**
   * Tasks: the one being tracked, in full, then the rest by name.
   *
   * It showed only the tracked quest, which was right when it was a small
   * panel floating over a map. It has half the rail now, and a panel headed
   * "Tasks" that lists one task is a panel that looks broken — so the others
   * are here too, clickable, because switching which job you are following is
   * the thing you actually want from this panel.
   */
  function renderQuestTracker() {
    const box = $('#quest-tracker');
    if (!box) return;
    const active = (G().state.quests || []).filter((x) => x.status === 'active');
    const q = trackedQuest();

    if (!active.length) {
      box.innerHTML = `<h2>Tasks</h2>
        <div class="track-body">
          <p class="help-hint">Nothing on the go. Take work from Available work
            in your journal (J), or ask around — most people in Rivermark want
            something.</p>
        </div>`;
      return;
    }

    // The stage's objective, not the quest's description: the description says
    // what the job is and never changes, and a tracker that repeats it tells
    // the player nothing about what to do next.
    const head = q ? `
      <div class="track-current">
        <div class="track-title">${esc(q.title)}</div>
        ${q.current_stage_title ? `<div class="track-stage">${esc(q.current_stage_title)}</div>` : ''}
        <p class="track-objective">${esc(q.current_objective || q.description || '')}</p>
        ${directionsTo(q.quest_key)}
      </div>` : '';

    const others = active.filter((x) => !q || x.quest_key !== q.quest_key);
    const rest = others.length ? `
      <div class="track-others">
        <h3>Also underway</h3>
        <ul>
          ${others.map((x) => `<li>
            <button type="button" class="track-pick" data-track="${esc(x.quest_key)}"
                    title="Follow this one instead">
              <span class="track-pick-title">${esc(x.title)}</span>
              ${x.current_stage_title
                ? `<span class="track-pick-stage">${esc(x.current_stage_title)}</span>` : ''}
            </button>
          </li>`).join('')}
        </ul>
      </div>` : '';

    box.innerHTML = `<h2>Tasks <span class="loc-count">${active.length}</span></h2>
      <div class="track-body">${head}${rest}</div>`;

    $$('[data-track]', box).forEach((b) => {
      b.addEventListener('click', async () => {
        try {
          await API.post('quest/track', { quest_key: b.dataset.track });
          const r = await API.get('quest/list');
          G().state.quests = r.mine || [];
          G().state.questMarkers = r.markers || [];
          renderQuestTracker();
        } catch (e) {
          G().showError(e.message);
        }
      });
    });
  }

  /**
   * Where to go next for a quest, in words.
   *
   * The tracker used to print the destination map's name and stop, which is
   * only useful to somebody who already knows the way — the warren is through
   * Rivermark's east gate and two doorways from the inn where the job is
   * taken. The route is computed server-side by LocationEngine::routeTo, so this
   * only has to choose between "you are here", "go through that door", and
   * "there is no way there from here".
   */
  function directionsTo(questKey) {
    const marker = (G().state.questMarkers || []).find((m) => m.quest === questKey)
      || (G().state.questMarkers || []).find((m) => m.tracked);
    if (!marker) return '';

    if (!marker.route || !marker.route.reachable) {
      return `<div class="track-where">${esc(marker.location_name || 'Somewhere else')}
        <span class="track-far">no known way there yet</span></div>`;
    }
    if (marker.route.arrived) {
      // "You are here" is a fact, not an instruction, and it was the whole of
      // what the tracker said at the exact moment the player most needs the
      // next step. Name what is in front of them — only what the scene is
      // already showing, so this points rather than spoils.
      const here = marker.here || {};
      const bits = [];
      if (here.encounter) bits.push(`<strong>${esc(here.encounter)}</strong> is waiting`);
      if ((here.npcs || []).length) {
        bits.push('talk to ' + here.npcs.map((n) => `<strong>${esc(n)}</strong>`).join(', '));
      }
      return `<div class="track-where is-here">You are here.
        ${bits.length
          ? `<span class="track-here-what">${bits.join(' · ')}</span>`
          : '<span class="track-far">Search the place, or look for the way on.</span>'}
      </div>`;
    }
    // The chain reads "The High Street → The Golden Flagon", so the player can
    // see how far off it is rather than only the next door. The first hop is
    // clickable: the tracker knowing the way and making you walk it by hand
    // would be a worse tracker.
    const chain = (marker.route.route || []).join(' → ');
    const hops = marker.route.hops || 0;
    const next = marker.route.next_location_id;
    return `<div class="track-where">
      <button type="button" class="track-go" data-map-node="${next}"
              title="Set off toward ${esc(marker.location_name || '')}">
        ${esc(marker.route.via)}
      </button>
      <span class="track-far">${esc(chain)} · ${hops} stop${hops === 1 ? '' : 's'}</span>
    </div>`;
  }

  // =========================================================================
  // The party rail
  // =========================================================================

  /** The companion row for a party member, if they are one. */
  function companionFor(pc) {
    return (G().state.companions || []).find((c) => c.character_id && c.character_id == pc.id) || null;
  }

  /**
   * How a companion feels, in words.
   *
   * A raw number would be a score to optimise. The bands are what the player is
   * meant to read, and the number sits in the title attribute for anyone who
   * wants it.
   *
   * The bands used to be fixed — Cold from -15 to -40, Hostile below — and that
   * was wrong in the one place it mattered. `leave_at` is authored per
   * companion and runs from -25 (Sera) to -40 (Kessa), so -20 read as the same
   * mild "Cold" for both when it is five points off losing Sera and twenty
   * clear for Kessa. A player who is told "Cold" twice and loses one of them
   * has been misinformed, not unlucky. The line a companion actually walks out
   * on is now what the bands are measured from, and the ten points above it get
   * their own band and their own colour, so the warning arrives before the
   * long rest rather than in the log afterwards.
   *
   * `comp` is a row from `state.companions`, which carries leave_at and
   * hostile_at. It is optional: the dialogue theatre tints an aside from the
   * approval on the interjection alone and has no row to hand.
   */
  function approvalBand(n, comp) {
    n = Number(n) || 0;
    const leaveAt = Number(comp && comp.leave_at != null ? comp.leave_at : -40);
    const hostileAt = Number(comp && comp.hostile_at != null ? comp.hostile_at : leaveAt - 30);
    if (n <= hostileAt) return { label: 'Hostile', cls: 'ap-bad', risk: 'hostile', leaveAt };
    if (n <= leaveAt) return { label: 'Leaving', cls: 'ap-bad', risk: 'leaving', leaveAt };
    if (n <= leaveAt + 10) return { label: 'At risk', cls: 'ap-risk', risk: 'edge', leaveAt };
    if (n >= 40) return { label: 'Devoted', cls: 'ap-high', leaveAt };
    if (n >= 15) return { label: 'Warm', cls: 'ap-good', leaveAt };
    if (n < 0) return { label: 'Cold', cls: 'ap-low', leaveAt };
    return { label: 'Neutral', cls: 'ap-mid', leaveAt };
  }

  /**
   * Which way a companion is moving, since the last time the player looked.
   *
   * The number on its own is a standing; the direction is the feedback. The
   * player who wrote "I couldn't get his affection high enough" had no way of
   * telling whether the last hour had helped, because nothing in the interface
   * ever said a value had changed.
   *
   * Held on the client rather than the server: it is "since you last looked",
   * which is a property of looking. The delta is sticky — it survives redraws
   * and only moves when the value does — so a conversation's effect is still on
   * the card when the player closes the dialogue and goes back to the fire.
   *
   * ...and it survives a RELOAD, which is the whole reason this is in
   * localStorage rather than a bare Map. It was a Map, and a Map is empty on
   * page load: every companion was met for the first time again, every baseline
   * reset, and every arrow vanished at once. A player who had spent an hour
   * raising three people, then refreshed, saw all the evidence of it disappear
   * together and reasonably concluded the save had rolled back. Nothing had —
   * the approval was in the database the whole time — but an indicator that
   * clears itself exactly when somebody comes back to check is worse than no
   * indicator at all, because it does not fail quietly, it lies.
   *
   * Keyed by party so two saves in one browser do not read each other's
   * baselines, and wrapped because localStorage throws rather than returning
   * null in private-browsing modes; a lost arrow is not worth an exception that
   * takes the party rail down with it.
   */
  const APPROVAL_SEEN_KEY = 'rpg.approvalSeen';
  let approvalSeen = new Map();
  let approvalParty = null;

  function approvalStoreKey(partyId) {
    return `${APPROVAL_SEEN_KEY}.${partyId ?? 'none'}`;
  }

  function loadApprovalSeen(partyId) {
    try {
      const raw = window.localStorage.getItem(approvalStoreKey(partyId));
      if (!raw) return new Map();
      const obj = JSON.parse(raw);
      return new Map(Object.entries(obj).filter(([, v]) => v && typeof v.at === 'number'));
    } catch (e) {
      return new Map();
    }
  }

  function saveApprovalSeen(partyId) {
    try {
      window.localStorage.setItem(
        approvalStoreKey(partyId),
        JSON.stringify(Object.fromEntries(approvalSeen))
      );
    } catch (e) {
      /* Private mode, or the quota is full. The arrows are a courtesy. */
    }
  }

  function approvalDrift(comp) {
    if (!comp || !comp.companion_key) return null;
    const partyId = G().state.partyId ?? null;
    if (approvalParty !== partyId) {
      approvalSeen = loadApprovalSeen(partyId);
      approvalParty = partyId;
    }
    const now = Number(comp.approval) || 0;
    const prev = approvalSeen.get(comp.companion_key);
    if (!prev) {
      // First sight of a companion is a baseline, not a swing of +N from zero.
      // Only true now on a genuinely new save, because the baselines outlive
      // the page.
      approvalSeen.set(comp.companion_key, { at: now, delta: 0 });
      saveApprovalSeen(partyId);
      return null;
    }
    if (prev.at !== now) {
      prev.delta = now - prev.at;
      prev.at = now;
      saveApprovalSeen(partyId);
    }
    return prev.delta ? prev.delta : null;
  }

  /** The pill, the arrow, and the arithmetic behind both. */
  function approvalPill(comp) {
    const ap = approvalBand(comp.approval, comp);
    const drift = approvalDrift(comp);
    const title = `Approval ${comp.approval} — ${ap.label.toLowerCase()}`
      + `\nWalks out at ${ap.leaveAt}`
      + (drift ? `\n${drift > 0 ? 'Up' : 'Down'} ${Math.abs(drift)} since you last looked` : '')
      + '\nSit with them at camp — most of what a companion thinks is decided there';
    return `<span class="party-approval ${ap.cls}" title="${esc(title)}">${esc(ap.label)}</span>`
      + (drift ? `<span class="approval-drift ${drift > 0 ? 'is-up' : 'is-down'}"
                       title="${esc(title)}">${drift > 0 ? '▲' : '▼'}${esc(Math.abs(drift))}</span>` : '');
  }

  /** The combatant a party member is, while a fight is running. */
  function combatantFor(pc) {
    const st = G().state;
    if (st.mode !== 'combat' || !st.combat?.state) return null;
    return (st.combat.state.combatants || []).find((k) => k.character_id == pc.id) || null;
  }

  /**
   * Conditions on a party member.
   *
   * A `characters` row has no conditions column — they live in combat state —
   * so outside a fight the only pip there is to show is being down, which is
   * read off hit points instead.
   */
  function conditionsFor(pc) {
    const c = combatantFor(pc);
    if (c) return (c.conditions || []).map((x) => (typeof x === 'string' ? x : x.key));
    return +pc.current_hp <= 0 ? ['unconscious'] : [];
  }

  const CONDITION_PIPS = {
    blinded: 'BL', charmed: 'CH', frightened: 'FR', grappled: 'GR',
    incapacitated: 'IN', invisible: 'IV', paralyzed: 'PA', poisoned: 'PO',
    prone: 'PR', restrained: 'RE', stunned: 'ST', unconscious: 'DN',
  };

  function renderPartyRail() {
    const rail = $('#party-rail');
    if (!rail) return;
    const st = G().state;
    const members = (st.party && st.party.length ? st.party : [st.character]).filter(Boolean);

    rail.innerHTML = '';
    members.forEach((member) => {
      // Mid-fight the truth about hit points is in combat state; the
      // `characters` row is only written back when the fight ends, so drawing
      // from it would show a full bar under a character who is bleeding out.
      const inFight = combatantFor(member);
      const pc = inFight
        ? { ...member, current_hp: inFight.hp, max_hp: inFight.max_hp, armor_class: inFight.ac }
        : member;

      const isActive = pc.id == st.character?.id;
      const down = pc.current_hp != null && +pc.current_hp <= 0;
      const comp = companionFor(pc);
      const conds = conditionsFor(member);

      const card = document.createElement('button');
      card.type = 'button';
      // The band class also colours the HP ring in the HUD, so the ring and the
      // bar cannot disagree — both come out of hpBand().
      card.className = 'party-card hp-' + G().hpBand(pc.current_hp, pc.max_hp)
        + (isActive ? ' is-active' : '')
        + (down ? ' is-down' : '');
      // The portrait shows no name or numbers, so the tooltip carries them.
      // Everything here was already on the card; it is only where it is read.
      card.title = down
        ? `${pc.name} is down — heal them or rest`
        : `${pc.name} — ${pc.current_hp}/${pc.max_hp} HP · Lv ${pc.level ?? 1}`
          + ` · AC ${pc.armor_class}\nOpen character sheet`;
      card.style.setProperty('--hp', String(G().hpPct(pc.current_hp, pc.max_hp) / 100));

      card.innerHTML = `
        <img class="party-face" src="${esc(G().classArtUrl(pc, '_face'))}" alt="">
        <div class="party-name">${esc(pc.name)}</div>
        <div class="hp-bar"><span class="${G().hpBand(pc.current_hp, pc.max_hp)}" style="width:${G().hpPct(pc.current_hp, pc.max_hp)}%"></span></div>
        ${pc.xp_progress ? `<div class="xp-bar rail-xp"
            title="Level ${esc(pc.level)}${pc.xp_progress.next_level
              ? ' · ' + esc(pc.xp_progress.remaining) + ' XP to level ' + esc(pc.xp_progress.next_level)
              : ' · max level'}"><span style="width:${esc(pc.xp_progress.percent)}%"></span></div>` : ''}
        <div class="party-stats">
          <span class="party-hp">${esc(pc.current_hp)}/${esc(pc.max_hp)}</span>
          <span class="party-lv" title="Level">Lv ${esc(pc.level ?? 1)}</span>
          <span class="party-ac" title="Armour class">AC ${esc(pc.armor_class)}</span>
        </div>
        <div class="cond-pips">
          ${conds.map((k) => `<span class="pip pip-${esc(k)}" title="${esc(k)}">${esc(CONDITION_PIPS[k] || '?')}</span>`).join('')}
        </div>
        ${down ? '<div class="party-downed">Down</div>' : ''}`;

      card.addEventListener('click', () => openCharacterSheet(pc.id));
      rail.appendChild(card);
    });

    // A party holds four (api/index.php refuses a fifth), so the empty slot is
    // an invitation rather than a button that fails.
    //
    // The party id goes in the link. Creation treats an absent one as "start a
    // new party", so relying on the session to supply it is exactly what made
    // the main menu's Create Character join the party you were already playing.
    const partyId = st.partyId ?? null;
    if (members.length < PARTY_CAP && partyId) {
      const add = document.createElement('a');
      add.className = 'party-card party-add';
      add.href = 'create.php?party_id=' + encodeURIComponent(partyId);
      // The HUD hides the word and shows only the plus, so the accessible name
      // has to come from somewhere that survives that.
      add.title = 'Recruit a companion';
      add.setAttribute('aria-label', 'Recruit a companion');
      add.innerHTML = '<span class="party-add-plus">+</span><span>Recruit</span>';
      rail.appendChild(add);
    }
  }

  // =========================================================================
  // The action bar
  // =========================================================================

  /**
   * Somebody worth the Talk button.
   *
   * Everyone in the scene is within reach — a location is one room, not a
   * grid — so this is about who the button should mean. The authored cast
   * comes first and the crowd only if there is nobody else, because "Talk" on
   * a market square should offer the captain rather than whichever beggar
   * happens to sort first.
   */
  function npcInReach() {
    const st = G().state;
    const npcs = (st.location && st.location.npcs) || [];
    if (!npcs.length) return null;
    return npcs.find((n) => !n.is_ambient) || npcs[0];
  }

  function renderActionBar() {
    const bar = $('#action-bar');
    if (!bar) return;
    const st = G().state;

    // Combat draws its own action bar inside the board, where the action
    // economy it belongs to is visible.
    if (st.mode === 'combat') {
      bar.innerHTML = '';
      bar.classList.add('hidden');
      return;
    }
    bar.classList.remove('hidden');

    const npc = npcInReach();
    const npcName = npc ? (npc.known ? npc.name : (npc.role || 'them')) : null;
    const buttons = [
      {
        label: 'Talk',
        hint: npc ? `Speak to ${npcName}` : 'Nobody here to talk to',
        disabled: !npc,
        run: () => G().talkTo(+npc.id),
      },
      { label: 'Search', hint: 'Look the place over', run: () => G().search() },
      { label: 'Camp', hint: 'Rest, and see how the party is taking it', run: openCamp },
      { label: 'Journal', hint: 'Quests taken and quests done', run: openJournal },
    ];

    bar.innerHTML = '';
    buttons.forEach((b) => {
      const el = document.createElement('button');
      el.type = 'button';
      el.className = 'btn action-btn';
      el.textContent = b.label;
      el.title = b.hint;
      el.disabled = !!b.disabled;
      el.addEventListener('click', () => b.run());
      bar.appendChild(el);
    });
  }

  // =========================================================================
  // Journal
  // =========================================================================

  async function openJournal() {
    let data;
    try {
      data = await API.get('quest/list');
    } catch (e) {
      G().showError(e.message);
      return;
    }
    G().state.quests = data.mine || [];
    renderQuestTracker();

    const mine = data.mine || [];
    const board = data.board || [];
    const active = mine.filter((q) => q.status === 'active');
    const done = mine.filter((q) => q.status === 'completed' || q.status === 'failed');

    /**
     * One quest, shut.
     *
     * A journal that has been played for a while is mostly stage history —
     * every beat of every quest, printed — and reading the one you are on
     * meant scrolling past all of it. So each entry is a disclosure: the line
     * that says which quest and how it stands is always visible, and the
     * playthrough is a click away.
     *
     * `<details>` rather than a click handler because the browser already has
     * this control: it opens on Enter and Space, announces its state, and is
     * findable by the browser's own find-in-page, none of which a div with an
     * onclick gets for free.
     */
    const questHtml = (q) => {
      const badge = q.status === 'completed' ? 'done' : q.status === 'active' ? 'ok' : '';
      // The stage history is what actually happened, in order — the journal
      // prints the playthrough rather than the quest's design.
      const history = (q.history || [])
        .filter((h) => h.journal_entry)
        .map((h) => `<p class="journal-entry">${esc(h.journal_entry)}</p>`)
        .join('');
      // The objective rides on the summary as well as inside: what to do next
      // is the one line worth reading without opening anything.
      const gist = q.status === 'active' && q.current_objective
        ? `<span class="quest-gist">${esc(q.current_objective)}</span>` : '';
      return `<details class="quest-item" data-quest="${esc(q.quest_key)}">
        <summary class="quest-summary">
          <span class="quest-summary-text">
            <span class="quest-title">${esc(q.title)}</span>
            ${gist}
          </span>
          <span class="badge ${badge}">${esc(q.status)}</span>
        </summary>
        <div class="quest-body">
          <p>${esc(q.description)}</p>
          ${q.current_objective ? `<p class="quest-objective"><em>${esc(q.current_objective)}</em></p>` : ''}
          ${history}
          <p class="quest-meta">Reward: ${esc(q.reward_xp)} XP, ${esc(q.reward_gold)} gp
            ${q.giver_name ? ' · From: ' + esc(q.giver_name) : ''}
            ${q.status === 'active' && q.target_location_name
              ? ' · Where: ' + esc(q.target_location_name) : ''}</p>
          ${q.status === 'active' && q.quest_key
            ? `<button type="button" class="btn btn-small ${+q.is_tracked ? 'btn-primary' : ''}" data-track="${esc(q.quest_key)}">
                ${+q.is_tracked ? 'Tracking' : 'Track this'}</button>`
            : ''}
        </div>
      </details>`;
    };

    const boardHtml = board.length
      ? board.map((q) => `<div class="quest-item">
          <h3>${esc(q.title)}</h3>
          <p>${esc(q.description)}</p>
          <p class="quest-meta">Reward: ${esc(q.reward_xp)} XP, ${esc(q.reward_gold)} gp</p>
          ${q.quest_key
            ? `<button type="button" class="btn btn-small btn-primary" data-accept="${esc(q.quest_key)}">Accept</button>`
            : `<button type="button" class="btn btn-small btn-primary" data-accept-id="${esc(q.id)}">Accept</button>`}
        </div>`).join('')
      : `<p class="help-hint">Nothing new posted. Work goes up on the job board
          by the door of the Golden Flagon — read it there and it lands here.</p>`;

    const el = showModal(`
      ${modalHead('Journal')}
      <div class="sheet-section"><h3>In progress</h3>
        ${active.length ? active.map(questHtml).join('') : '<p class="help-hint">Nothing on the go.</p>'}</div>
      <div class="sheet-section"><h3>Finished</h3>
        ${done.length ? done.map(questHtml).join('') : '<p class="help-hint">Nothing behind you yet.</p>'}</div>
      <div class="sheet-section"><h3>Available work</h3>${boardHtml}</div>
    `, { slot: 'journal', size: 'wide', label: 'Journal' });

    // No [data-close] here any more: the title bar's × is wired by showModal.
    $$('[data-track]', el).forEach((b) => {
      b.onclick = async () => {
        try {
          await API.post('quest/track', { quest_key: b.dataset.track });
          closeTopModal();
          await G().refreshAll();
          openJournal();
        } catch (e) {
          G().showError(e.message);
        }
      };
    });
    // `quest/accept` takes either, and resolves both to the stable key —
    // the five hand-seeded quests predate quest_key and have only a row id.
    $$('[data-accept], [data-accept-id]', el).forEach((b) => {
      b.onclick = async () => {
        const body = b.dataset.accept
          ? { quest_key: b.dataset.accept }
          : { quest_id: +b.dataset.acceptId };
        try {
          const r = await API.post('quest/accept', body);
          if (r.message) G().log(r.message, 'important');
          closeTopModal();
          await G().refreshAll();
          openJournal();
        } catch (e) {
          G().showError(e.message);
        }
      };
    });
  }

  // =========================================================================
  // Camp
  // =========================================================================

  /**
   * The camp screen: the party, and everyone who is not currently with it.
   *
   * `location/rest` takes a room at the innkeeper's own price and
   * `location/camp` is free, and both are refused where the location does not
   * offer them — there is no inn in a cave and no camping in a taproom. The
   * buttons say which is which and what it costs, because calling something
   * Camp and charging inn prices would be a lie.
   */
  function openCamp() {
    const st = G().state;
    const roster = st.companions || [];
    // Everyone who is actually AT the fire. Somebody who has walked out or
    // turned on you is not sitting down with the party, and somebody never met
    // has no business on the roster at all.
    const here = roster.filter((c) => c.status === 'active' || c.status === 'camp');
    const away = roster.filter((c) => ['left', 'hostile', 'dead'].includes(c.status));

    // The party holds four counting the character you play, so the fire is
    // where a fifth waits. Read from the marching list rather than counted here
    // so the button agrees with CompanionService, which enforces the same cap
    // server-side and refuses rather than quietly declining.
    const marching = (st.party || []).length;
    const full = marching >= PARTY_CAP;

    // The rest of the party — the characters the player made, as opposed to the
    // authored companions above.
    //
    // This screen listed companions and nothing else, so a party of three
    // player characters and no companions was told "You are travelling alone",
    // with two of its own members standing in the firelight. The Old City makes
    // that the normal case rather than an edge one: it has a single companion
    // and he is recruited late, so until then every party sees it.
    //
    // They get a seat but not a companion's controls. There is no approval to
    // show, nobody to ask to wait behind, and no camp conversation to open —
    // those belong to characters somebody wrote, not to characters the player
    // rolled. What they are here for is to be counted.
    const you = Number(st.character?.id);
    const others = (st.party || []).filter(
      (c) => !c.companion_key && Number(c.id) !== you
    );

    const partyRow = (c) => `<div class="camp-row is-party">
      <img class="camp-face" src="${esc(G().spriteArtUrl(c.sprite_key, '_face'))}"
           alt="" onerror="this.remove()">
      <div class="camp-ident">
        <strong>${esc(c.name)}</strong>
        <div class="sub">${esc(c.race || '')}${c.race ? ' · ' : ''}${esc(c.class)} ${esc(c.level)}
          · HP ${esc(c.current_hp)}/${esc(c.max_hp)}</div>
      </div>
    </div>`;

    const marchButton = (c) => {
      if (c.status === 'camp') {
        const why = full
          ? `The party is full (${PARTY_CAP}) — ask somebody to wait here first`
          : `Bring ${c.name} along`;
        return `<button type="button" class="btn btn-small camp-march"
                        data-march="${esc(c.companion_key)}" data-to="active"
                        title="${esc(why)}"${full ? ' disabled' : ''}>Bring along</button>`;
      }
      return `<button type="button" class="btn btn-small camp-march"
                      data-march="${esc(c.companion_key)}" data-to="camp"
                      title="${esc(c.name + ' stays at camp until you ask for them')}">Ask to wait here</button>`;
    };

    // Somebody about to walk out is the one thing the fire has to say out loud.
    // The threshold is authored per companion and the player has no way to see
    // it, so the seat says the number and what closes the gap — which is
    // sitting down with them, because that is now where the standing is.
    const riskLine = (c, ap) => {
      if (ap.risk === 'hostile') return `${c.name} will turn on you. This is past mending.`;
      if (ap.risk === 'leaving') return `${c.name} leaves at the next long rest — ${c.approval} against ${ap.leaveAt}.`;
      return `${c.name} is close to walking — ${c.approval} against ${ap.leaveAt}. Sit with them.`;
    };

    // The heart, when there is one to show. `romance_stage`/`romance_locked`
    // have been shipped to the client since romance existed; this is the
    // first thing to read them.
    const heartPill = (c) => {
      const stage = Number(c.romance_stage || 0);
      if (stage >= 2) {
        return '<span class="camp-heart is-committed" title="You are together">❤</span>';
      }
      if (stage >= 1) {
        return '<span class="camp-heart" title="Something has started between you">❤</span>';
      }
      if (Number(c.romance_locked || 0) === 1) {
        return '<span class="camp-heart is-locked" title="Their heart is settled — you chose someone else">·</span>';
      }
      return '';
    };

    const seat = (c) => {
      const ap = approvalBand(c.approval, c);
      // The camp art is optional and generated separately; until a companion
      // has one the seat falls back to their portrait, which is why the <img>
      // carries both and swaps on error rather than the JS probing for files.
      const scene = `assets/images/camp/${encodeURIComponent(c.companion_key)}.png`;
      const face = G().spriteArtUrl(c.sprite_key, '_face');
      // Wrapped because the seat is itself a button — sitting down with
      // somebody — and a button cannot hold another one.
      return `<div class="camp-seat-wrap">
        <button type="button" class="camp-seat" data-talk="${esc(c.npc_key || '')}"
                      data-name="${esc(c.name)}" data-key="${esc(c.companion_key)}"
                      title="Sit with ${esc(c.name)}"${c.npc_key ? '' : ' disabled'}>
        <img class="camp-seat-art" src="${esc(scene)}" alt=""
             onerror="this.onerror=null;this.src='${esc(face)}';this.classList.add('is-portrait')">
        <span class="camp-seat-body">
          <span class="camp-seat-name">${esc(c.name)}</span>
          ${c.title ? `<span class="camp-title">${esc(c.title)}</span>` : ''}
          <span class="camp-seat-meta">
            ${approvalPill(c)}
            ${heartPill(c)}
            ${c.status === 'camp' ? '<span class="camp-waiting">waiting here</span>' : ''}
          </span>
          ${ap.risk ? `<span class="camp-seat-risk">${esc(riskLine(c, ap))}</span>` : ''}
        </span>
        </button>
        ${c.npc_key ? `<button type="button" class="btn btn-small camp-talk"
              data-talk-start="${esc(c.npc_key)}" data-name="${esc(c.name)}"
              title="Draw them aside — the whole of what they have to say, not just the fireside">Talk</button>` : ''}
        ${marchButton(c)}
      </div>`;
    };

    // What "left" means, and that it is not the end of it. The reconciliation
    // scenes are authored on the companion's own dialogue, at the place the
    // party first met them, and nothing else in the interface says so.
    const GONE = {
      left: 'walked out — they have gone back to where you first found them',
      hostile: 'turned on you',
      dead: 'dead',
    };
    const goneRow = (c) => `<div class="camp-row is-gone">
      <img class="camp-face" src="${esc(G().spriteArtUrl(c.sprite_key, '_face'))}" alt="">
      <div class="camp-ident">
        <strong>${esc(c.name)}</strong>
        <div class="sub">${esc(GONE[c.status] || c.status)}</div>
      </div>
    </div>`;

    // Where the party is standing decides whether either kind of rest is on
    // offer, and the server refuses both independently. Offering them anyway
    // is what made "Make camp" a 400 on a street corner: the screen opens from
    // the HUD anywhere in the world, but only four places in it allow camping
    // and only one lets rooms. The price comes off the location too — it was
    // written into the button as 5 gp, which was right for exactly one inn.
    const loc = st.location || {};
    const roomCost = loc.inn_cost === null || loc.inn_cost === undefined
      ? null : Number(loc.inn_cost);
    // Mirrors LocationEngine::canCampHere exactly — allow_camp OR an inn.
    // Somewhere with beds will also let you sleep on the floor for nothing,
    // and reading only allow_camp here would have greyed out Make camp in the
    // one building where the server allows it.
    const canCamp = !!loc.allow_camp || roomCost !== null;
    const gold = Number(st.character?.gold ?? 0);
    const campHint = 'The road east out of Rivermark has open ground, '
      + 'and the Golden Flagon has beds.';

    // Making camp is what resolves a companion who has had enough, so the
    // warning has to be on this screen and above the button that fires it.
    const atRisk = here.filter((c) => approvalBand(c.approval, c).risk);
    const canReturn = away.some((c) => c.status === 'left');

    const el = showModal(`
      <div class="camp-scene">
        <h2 class="camp-head">Camp</h2>
        <p class="camp-intro">The fire is banked and the night is somebody
          else's problem for a few hours. Sit with someone.</p>

        ${atRisk.length ? `<p class="camp-warning">
          ${esc(atRisk.map((c) => c.name).join(' and '))}
          ${atRisk.length > 1 ? 'are' : 'is'} on the point of leaving.
          Sit with them before you rest — what a companion thinks of you is
          mostly decided at this fire.</p>` : ''}

        <div class="camp-seats">
          ${here.map(seat).join('')}
        </div>

        ${others.length ? `<div class="sheet-section camp-party">
          <h3>Also at the fire</h3>
          ${others.map(partyRow).join('')}
        </div>` : ''}

        ${!here.length && !others.length
          ? '<p class="help-hint">You are travelling alone. The fire is yours.</p>'
          : ''}

        ${away.length ? `<div class="sheet-section"><h3>No longer with you</h3>
          ${away.map(goneRow).join('')}
          ${canReturn ? `<p class="help-hint">Somebody who walked out can still be
            found and talked round, and they keep everything they left with. What
            it takes is theirs, not yours.</p>` : ''}</div>` : ''}

        <div class="camp-rest">
          <p class="help-hint">A long rest restores hit points and spell slots
            for everyone travelling with you — and it is where somebody who has
            had enough of the party chooses to say so.
            You have ${esc(gold)} gp.</p>
          <div class="camp-rest-actions">
            <button type="button" class="btn btn-primary" data-camp
                    ${canCamp ? '' : 'disabled'}
                    title="${esc(canCamp ? 'A free long rest, out in the open'
                      : 'There is no camping here — find open ground, or a bed')}">Make camp</button>
            ${roomCost === null
              ? `<button type="button" class="btn" data-rest disabled
                         title="Nobody here is letting rooms">Take a room</button>`
              : `<button type="button" class="btn" data-rest
                         ${gold >= roomCost ? '' : 'disabled'}
                         title="${esc(gold >= roomCost ? 'A bed, a long rest, and spells back'
                           : 'You cannot afford the room')}">Take a room (${esc(roomCost)} gp)</button>`}
          </div>
          ${canCamp || roomCost !== null ? '' : `<p class="help-hint camp-nowhere">
            You can sit with people anywhere, but there is nowhere to sleep here.
            ${esc(campHint)}</p>`}
        </div>
      </div>
      <div class="modal-actions"><button type="button" class="btn" data-close>Close</button></div>
    `, { slot: 'camp', size: 'wide', label: 'Camp' });

    el.querySelector('[data-close]').onclick = closeTopModal;

    // Sitting with somebody opens their camp conversation. The topics, and
    // which of them they are willing to raise, are authored on the companion's
    // own dialogue tree under the `camp` node — so this button knows nothing
    // about what they will say, which is the point.
    $$('[data-talk]', el).forEach((b) => {
      b.addEventListener('click', () => {
        const npcKey = b.dataset.talk;
        if (!npcKey) return;
        closeTopModal();
        window.Dialog.openNode(npcKey, 'camp', {
          camp: b.dataset.key, name: b.dataset.name,
        });
      });
    });

    // Talk is the other door: the companion's whole tree from its start node
    // (node_key null — the server resolves the tree's own start, which is
    // `hail` for Jimmy and `greeting` for the rest). This is what makes the
    // recruited-side greeting content — the settled idle pools, the fate
    // reactions, the romance arcs — reachable at all: recruited companions
    // are filtered out of the world's people list, and the seat button opens
    // the fireside hub only.
    $$('[data-talk-start]', el).forEach((b) => {
      b.addEventListener('click', () => {
        closeTopModal();
        // fromCamp, not camp: drawing somebody aside is not a fireside scene
        // and should not be painted as one — but it still owes you the fire
        // back when it ends, which is what this door was missing.
        window.Dialog.openNode(b.dataset.talkStart, null,
          { name: b.dataset.name, fromCamp: true });
      });
    });

    // Swapping who marches changes character_party, which the rail, the sheet
    // and the combat board are all drawn from — so the world is reloaded rather
    // than patched, and the fire redrawn from what came back.
    $$('[data-march]', el).forEach((b) => {
      b.addEventListener('click', async () => {
        b.disabled = true;
        const route = b.dataset.to === 'active'
          ? 'character/companion_activate'
          : 'character/companion_dismiss';
        try {
          const r = await API.post(route, { companion_key: b.dataset.march });
          ((r.result || {}).messages || []).forEach((m) => G().log(m, 'important'));
          ((r.result || {}).notes || []).forEach((n) => G().log('(' + n + ')', 'danger'));
          await G().refreshAll();
          closeTopModal();
          openCamp();
        } catch (err) {
          G().showError(err.message);
          b.disabled = false;
        }
      });
    });

    const rest = (route) => async (e) => {
      e.target.disabled = true;
      try {
        const r = await API.post(route, {});
        G().log(r.message, 'success');
        // Anyone who reached breaking point leaves at the fire, so the rest
        // has more to report than "you feel better".
        (r.messages || []).forEach((m) => G().log(m, 'story'));
        closeTopModal();
        await G().refreshAll();
      } catch (err) {
        G().showError(err.message);
        e.target.disabled = false;
      }
    };
    el.querySelector('[data-camp]').onclick = rest('location/camp');
    el.querySelector('[data-rest]').onclick = rest('location/rest');
  }

  // =========================================================================
  // Character sheet
  // =========================================================================

  let sheetCharacterId = null;

  async function openCharacterSheet(characterId) {
    sheetCharacterId = Number(characterId);
    try {
      const data = await API.get(
        'character/sheet&character_id=' + encodeURIComponent(characterId)
      );
      renderCharacterSheet(data.character, data.inventory || [], data.spells || [],
        data.spell_slots || []);
    } catch (e) {
      G().showError(e.message);
      sheetCharacterId = null;
    }
  }

  /**
   * The experience bar.
   *
   * `xp_progress` is computed server-side by Rules::xpProgress and arrives on
   * every character, so the ladder is never restated here — the spell slot
   * table was duplicated into the client once already and that is the bug this
   * avoids repeating.
   *
   * The bar is labelled with real numbers rather than a bare percentage,
   * because "1,150 / 2,700 to level 4" tells the player how much more work a
   * level is and a filled rectangle does not.
   */
  function xpBar(c) {
    const p = c.xp_progress;
    if (!p) return '';
    const capped = p.next_level === null;
    return `
      <div class="bar-row">
        <span class="bar-label">${capped ? 'Experience' : 'To level ' + esc(p.next_level)}</span>
        <div class="xp-bar"><span style="width:${esc(p.percent)}%"></span></div>
        <span class="bar-value">${capped
          ? esc(p.xp) + ' XP · max level'
          : esc(p.earned) + ' / ' + esc(p.needed)}</span>
      </div>`;
  }

  /**
   * Approval, as a bar with nothing in the middle.
   *
   * Zero is the centre and the bar grows out from it — left for a companion
   * who is going off you, right for one who is not. A left-anchored bar would
   * have to pick an arbitrary floor to measure from and would then read a
   * hostile companion as "a little bit full", which is exactly backwards.
   *
   * The scale is symmetric and drawn from their OWN thresholds: someone who
   * leaves at -25 and someone who leaves at -40 are not on the same ruler, and
   * a shared one would put Sera in visible danger and Kessa in comfort at the
   * same number. The line they walk out on is marked, because the number only
   * means something against it.
   *
   * Lives here rather than on the party rail because the rail is a glance —
   * who is hurt, who is down — and this is a thing you go and look at.
   */
  function approvalBar(comp) {
    if (!comp) return '';
    const val = Number(comp.approval) || 0;
    const band = approvalBand(val, comp);
    const leaveAt = band.leaveAt;
    // Far enough out that the walking-out line is never at the very edge, and
    // symmetric so the centre is honestly zero.
    const span = Math.max(Math.abs(leaveAt) + 15, 55);
    const pos = (v) => 50 + (Math.max(-span, Math.min(span, v)) / span) * 50;
    const here = pos(val);
    const from = Math.min(50, here);
    const width = Math.abs(here - 50);
    const drift = approvalDrift(comp);
    const arrow = drift ? (drift > 0 ? `▲${drift}` : `▼${Math.abs(drift)}`) : '';
    const title = `${val >= 0 ? '+' : ''}${val} · ${band.label}`
      + ` · walks out at ${leaveAt}`;
    return `
      <div class="bar-row">
        <span class="bar-label">Approval</span>
        <div class="ap-bar ${esc(band.cls)}" title="${esc(title)}">
          <span class="ap-axis"></span>
          <span class="ap-leave" style="left:${esc(pos(leaveAt))}%"
                title="Walks out at ${esc(leaveAt)}"></span>
          <span class="ap-fill" style="left:${esc(from)}%;width:${esc(width)}%"></span>
        </div>
        <span class="bar-value">${val >= 0 ? '+' : ''}${esc(val)} · ${esc(band.label)}${
          arrow ? ` <em class="ap-arrow">${esc(arrow)}</em>` : ''}</span>
      </div>${romanceLine(comp)}`;
  }

  /** One quiet line under the approval bar, when there is a heart to report. */
  function romanceLine(comp) {
    const stage = Number(comp?.romance_stage || 0);
    if (stage >= 2) return '<p class="sheet-romance">❤ You are together.</p>';
    if (stage >= 1) return '<p class="sheet-romance">❤ Something has started between you.</p>';
    if (Number(comp?.romance_locked || 0) === 1) {
      return '<p class="sheet-romance is-locked">Their heart has settled elsewhere.</p>';
    }
    return '';
  }

  /**
   * Spell slots, as dots: filled is spent, hollow is still yours.
   *
   * Server-supplied — `spell_slots` on the sheet payload is already have/used
   * per level, so nothing here knows the slot table. Same dots the combat
   * economy line draws, so a caster reads their slots the same way in both
   * places rather than learning two dialects.
   */
  function slotsHtml(slots) {
    if (!slots || !slots.length) return '';
    const total = slots.reduce((n, s) => n + s.left, 0);
    return `<div class="sheet-section">
      <h3>Spell slots</h3>
      <div class="sheet-slots">
        ${slots.map((s) => `
          <span class="slot-group" title="Level ${esc(s.level)}: ${esc(s.left)} of ${esc(s.have)} left">
            <b>${esc(s.level)}</b>
            ${Array.from({ length: s.have }, (_, i) =>
              `<i class="slot-dot ${i < s.used ? 'spent' : ''}"></i>`).join('')}
            <span class="slot-count">${esc(s.left)}/${esc(s.have)}</span>
          </span>`).join('')}
      </div>
      <p class="help-hint">${total
        ? 'A long rest returns every slot — camp, or take a room.'
        : 'Everything is spent. A long rest returns them all.'}</p>
    </div>`;
  }

  /**
   * The bag: one square per stack, and the detail underneath.
   *
   * It was a stacked list of cards, each carrying the name, the numbers and the
   * whole description — which is a page per sheet by the time somebody is
   * carrying a dozen things, and the answer to "what have I got" was a scroll
   * rather than a glance. A grid of tiles answers that in one look, and the
   * detail of ONE item goes in a panel below, where there is room to say all of
   * it without the other eleven repeating the trick.
   *
   * The grid is padded out to whole rows of empty slots on purpose: a bag with
   * space left in it should look like one.
   */
  const BAG_GLYPH = {
    weapon: 'i-attack', armor: 'i-shield', potion: 'i-potion',
    scroll: 'i-cast', treasure: 'i-dice', misc: 'i-hands',
  };
  const BAG_MIN_SLOTS = 12;

  function bagTile(it) {
    const art = it.image_url
      ? `<img class="bag-art" src="${esc(it.image_url)}" alt="" onerror="this.remove()">`
      : `<svg class="bag-glyph" aria-hidden="true"><use href="#${
          BAG_GLYPH[it.item_type] || 'i-hands'}"></use></svg>`;
    return `<button type="button" class="bag-slot is-${esc(it.rarity || 'common')}
        ${it.is_equipped == 1 ? 'is-equipped' : ''}"
        data-inv-id="${esc(it.id)}"
        title="${esc(it.name)}${it.quantity > 1 ? ' ×' + esc(it.quantity) : ''}">
      ${art}
      <span class="bag-label">${esc(it.name)}</span>
      ${it.quantity > 1 ? `<span class="bag-qty">${esc(it.quantity)}</span>` : ''}
      ${it.is_equipped == 1 ? '<span class="bag-worn" aria-label="Equipped">●</span>' : ''}
    </button>`;
  }

  function bagHtml(inventory) {
    const pad = Math.max(BAG_MIN_SLOTS - inventory.length,
      (6 - (inventory.length % 6)) % 6);
    return `<div class="bag-grid" role="list">
        ${inventory.map(bagTile).join('')}
        ${Array.from({ length: Math.max(0, pad) },
          () => '<span class="bag-slot is-empty" aria-hidden="true"></span>').join('')}
      </div>
      <div class="bag-detail" id="bag-detail">
        <p class="help-hint">${inventory.length
          ? 'Pick something up to read it, use it, or hand it to somebody.'
          : 'Nothing in the bag.'}</p>
      </div>`;
  }

  function renderCharacterSheet(c, inventory, spells, spellSlots) {
    const m = c.modifiers || {};
    const fmt = window.Game.fmtMod;
    const ability = (label, score, mod) =>
      `<div class="ability-box sheet-ab"><span>${label}</span><strong>${esc(score)}</strong><em>${fmt(mod)}</em></div>`;

    const invHtml = bagHtml(inventory);

    // The avatar is chosen during character creation and does not change
    // afterwards, so the sheet no longer offers a picker. It is what the
    // player looks like, not a setting.
    //
    // Healing spells get a Cast row outside combat — character/cast is the
    // campfire door, and it only opens for a mending, so only heals offer it.
    const castable = G().state.mode !== 'combat';
    const party = G().state.party || [];
    const castRow = (sp) => {
      if (!castable || sp.resolution !== 'heal') return '';
      const options = (party.length ? party : [c]).map((p) =>
        `<option value="${esc(p.id)}">${esc(p.name)} (${esc(p.current_hp)}/${esc(p.max_hp)})</option>`).join('');
      return `<div class="sheet-cast-row">
        <select data-cast-target="${esc(sp.id)}" aria-label="Who to heal">${options}</select>
        <button type="button" class="btn btn-small" data-cast="${esc(sp.id)}">Cast</button>
      </div>`;
    };
    const spellHtml = spells.length ? `
      <div class="sheet-section"><h3>Spells</h3><div class="sheet-spells">
        ${spells.map((sp) => `<div class="inv-item">
          <strong>${esc(sp.name)}</strong>
          <span class="badge">${sp.level == 0 ? 'Cantrip' : 'Lvl ' + esc(sp.level)}</span>
          <p>${esc(sp.school || '')}${sp.damage_dice ? ' · ' + esc(sp.damage_dice) + ' ' + esc(sp.damage_type || '') : ''}</p>
          <p class="inv-desc">${esc(sp.description || '')}</p>
          ${castRow(sp)}
        </div>`).join('')}
      </div></div>` : '';

    const el = showModal(`
      <div class="sheet-header">
        <img class="sheet-bust" src="${esc(G().classArtUrl(c, '_bust'))}" alt="">
        <div class="sheet-ident">
          <h2 class="sheet-title">${esc(c.name)}</h2>
          <p class="sheet-subtitle">
            Level ${esc(c.level)} ${esc(c.race)}${c.subrace ? ' (' + esc(c.subrace) + ')' : ''}
            ${esc(c.class)}${c.subclass ? ' · ' + esc(c.subclass) : ''}
          </p>
          <p class="sheet-subtitle">${esc(c.background || '')} · ${esc(c.alignment || '')}</p>
        </div>
        <div class="sheet-header-actions">
          <a class="btn btn-small" target="_blank" rel="noopener"
             href="sheet_print.php?character_id=${esc(c.id)}&amp;print=1"
             title="A paper sheet for the table, in the standard 5e layout. Opens in a new tab.">Print</a>
          ${c.companion_key
            ? '<span class="badge">Companion</span>'
            : (c.id != G().state.character?.id
              ? '<button type="button" class="btn btn-small btn-primary" data-make-active>Make Active</button>'
              : '<span class="badge ok">Active</span>')}
          <button type="button" class="btn btn-small" data-close>Close</button>
        </div>
      </div>

      <div class="sheet-combat-row">
        <div class="sheet-stat-pill"><span>HP</span><strong>${esc(c.current_hp)}/${esc(c.max_hp)}</strong></div>
        <div class="sheet-stat-pill"><span>AC</span><strong>${esc(c.armor_class)}</strong></div>
        <div class="sheet-stat-pill"><span>Speed</span><strong>${esc(c.speed)} ft</strong></div>
        <div class="sheet-stat-pill"><span>Prof</span><strong>+${esc(c.proficiency_bonus ?? 2)}</strong></div>
        ${c.companion_key ? '' : `<a class="sheet-stat-pill sheet-redress"
          href="create.php?redress=${esc(c.id)}"
          title="Rebuild how they look from the modular layers">
          <span>Look</span><strong>change</strong></a>`}
        <button type="button" class="sheet-stat-pill sheet-rank" data-rank
          title="Which end of your side of the battlefield they deploy at when a fight starts. They can walk anywhere once it has.">
          <span>Deploys</span><strong>${esc(c.combat_rank || 'front')}</strong></button>
        <div class="sheet-stat-pill"><span>Gold</span><strong>${esc(c.gold ?? 0)} gp</strong></div>
      </div>
      <div class="bar-row">
        <span class="bar-label">Hit points</span>
        <div class="hp-bar sheet-hp"><span class="${G().hpBand(c.current_hp, c.max_hp)}" style="width:${G().hpPct(c.current_hp, c.max_hp)}%"></span></div>
        <span class="bar-value">${esc(c.current_hp)} / ${esc(c.max_hp)}</span>
      </div>
      ${xpBar(c)}
      ${approvalBar((G().state.companions || [])
        .find((x) => x.companion_key && x.companion_key === c.companion_key))}

      <div class="sheet-section">
        <h3>Ability Scores</h3>
        <div class="ability-grid sheet-abilities">
          ${ability('STR', c.strength, m.strength)}
          ${ability('DEX', c.dexterity, m.dexterity)}
          ${ability('CON', c.constitution, m.constitution)}
          ${ability('INT', c.intelligence, m.intelligence)}
          ${ability('WIS', c.wisdom, m.wisdom)}
          ${ability('CHA', c.charisma, m.charisma)}
        </div>
      </div>

      ${slotsHtml(spellSlots)}

      <div class="sheet-section">
        <h3>Inventory</h3>
        <div id="sheet-inv-list">${invHtml}</div>
      </div>
      ${spellHtml}
    `, {
      size: 'wide',
      label: c.name,
      // The slot makes clicking a second party card swap the sheet rather than
      // bury the first. onClose only forgets the open sheet if it is still this
      // one — the swap fires the outgoing sheet's onClose after the new id is
      // already set, and without the guard that would blank it.
      slot: 'character-sheet',
      onClose: () => { if (sheetCharacterId === Number(c.id)) sheetCharacterId = null; },
    });

    el.querySelector('[data-close]').onclick = closeTopModal;
    el.querySelector('[data-make-active]')?.addEventListener('click', async () => {
      closeTopModal();
      await G().setActiveCharacter(c.id);
    });

    $$('[data-cast]', el).forEach((b) => {
      b.onclick = async () => {
        const spellId = Number(b.dataset.cast);
        const sel = el.querySelector(`[data-cast-target="${spellId}"]`);
        b.disabled = true;
        try {
          const r = await API.post('character/cast', {
            character_id: c.id,
            spell_id: spellId,
            target_character_id: sel ? Number(sel.value) : c.id,
          });
          G().log(r.message);
          G().mergeCharacter(r.character);
          if (r.caster) G().mergeCharacter(r.caster);
          renderPartyRail();
          // Reopen so the slot dots and HP bars tell the truth about what the
          // cast just spent.
          closeTopModal();
          openCharacterSheet(c.id);
        } catch (ex) {
          G().showError(ex.message);
          b.disabled = false;
        }
      };
    });

    // Toggling rather than offering two buttons: there are exactly two ends,
    // and a chip showing the current one that flips is less furniture than a
    // pair of radios saying the same thing.
    el.querySelector('[data-rank]')?.addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        const r = await API.post('character/rank', {
          character_id: c.id,
          rank: (c.combat_rank || 'front') === 'front' ? 'back' : 'front',
        });
        G().mergeCharacter(r.character);
        G().log(`${r.character.name} will deploy at the ${r.character.combat_rank} of the line.`, 'success');
        closeTopModal();
        await openCharacterSheet(c.id);
      } catch (err) {
        G().showError(err.message);
        btn.disabled = false;
      }
    });


    bindBag($('#sheet-inv-list', el), inventory, c);
  }

  /**
   * The bag's one selection, and what can be done with what is in it.
   *
   * Delegated from the grid rather than bound per tile: the detail panel is
   * rewritten on every pick, and handlers hung on the tiles would have to be
   * re-hung with it.
   */
  function bindBag(host, inventory, c) {
    if (!host) return;
    const detail = $('#bag-detail', host);

    host.addEventListener('click', (e) => {
      const tile = e.target.closest('.bag-slot[data-inv-id]');
      if (!tile) return;
      $$('.bag-slot', host).forEach((t) => t.classList.remove('is-picked'));
      tile.classList.add('is-picked');
      const it = inventory.find((x) => String(x.id) === tile.dataset.invId);
      if (it) showBagItem(detail, it, c);
    });
  }

  /** Who else is in the party — the people an item can be handed to. */
  function bagRecipients(c) {
    return (G().state.party || []).filter((p) => p && p.id != c.id);
  }

  function showBagItem(detail, it, c) {
    if (!detail) return;
    const wearable = it.item_type === 'weapon' || it.item_type === 'armor' || it.slot;
    // Scrolls read from the sheet too; misc consumables (antitoxin, oils) are
    // combat-only and the server says so if tried here.
    const usable = it.item_type === 'potion' || it.item_type === 'scroll'
      || (it.item_type === 'misc' && (it.properties || '').includes('use_effect'));
    const mates = bagRecipients(c);

    const facts = [
      it.quantity > 1 ? '×' + esc(it.quantity) : '',
      esc(it.item_type),
      it.rarity && it.rarity !== 'common' ? esc(it.rarity) : '',
      it.damage_dice ? esc(it.damage_dice) + (it.damage_type ? ' ' + esc(it.damage_type) : '') : '',
      it.armor_bonus != null ? 'AC ' + esc(it.armor_bonus) : '',
      it.weight ? esc(it.weight) + ' lb' : '',
      esc(it.value_gp ?? 0) + ' gp',
    ].filter(Boolean).join(' · ');

    detail.innerHTML = `
      <div class="bag-detail-head">
        <strong class="bag-detail-name is-${esc(it.rarity || 'common')}">${esc(it.name)}</strong>
        ${it.is_equipped == 1 ? '<span class="badge ok">equipped</span>' : ''}
      </div>
      <p class="bag-detail-facts">${facts}</p>
      <p class="inv-desc">${esc(it.description || '')}</p>
      <div class="sheet-item-actions">
        ${wearable ? `<button type="button" class="btn btn-small" data-equip>${
          it.is_equipped == 1 ? 'Unequip' : 'Equip'}</button>` : ''}
        ${usable ? '<button type="button" class="btn btn-small" data-use>Use</button>' : ''}
      </div>
      ${mates.length ? `<div class="bag-give">
        <span class="bag-give-label">Hand to</span>
        ${it.quantity > 1 ? `<label class="bag-qty-pick">how many
          <input type="number" min="1" max="${esc(it.quantity)}" value="${esc(it.quantity)}"
                 data-give-qty></label>` : ''}
        <div class="bag-give-who">
          ${mates.map((p) => `<button type="button" class="btn btn-small" data-give="${esc(p.id)}"
             title="Give ${esc(it.name)} to ${esc(p.name)}">${esc(p.name)}</button>`).join('')}
        </div>
      </div>` : ''}`;

    detail.querySelector('[data-equip]')?.addEventListener('click',
      () => equipItem(it.id, it.is_equipped != 1, c.id));
    detail.querySelector('[data-use]')?.addEventListener('click', () => useItem(it.id, c.id));
    $$('[data-give]', detail).forEach((b) => {
      b.onclick = () => {
        const box = detail.querySelector('[data-give-qty]');
        const qty = box ? Math.max(1, Math.min(Number(box.value) || 1, it.quantity)) : 1;
        giveItem(it.id, Number(b.dataset.give), c.id, it.quantity > 1 ? qty : null);
      };
    });
  }

  /**
   * Hand an item to somebody else in the party.
   *
   * Both sheets change, so both characters come back and both are merged: the
   * giver may have taken armour off to hand it over, and the party rail draws
   * an AC that would otherwise be a turn out of date.
   */
  async function giveItem(inventoryId, toId, fromId, qty) {
    try {
      const r = await API.post('inventory/give', {
        inventory_id: inventoryId,
        to_character_id: toId,
        character_id: fromId || sheetCharacterId || G().state.character.id,
        ...(qty ? { quantity: qty } : {}),
      });
      G().mergeCharacter(r.character);
      G().mergeCharacter(r.recipient);
      if (r.character?.id == G().state.character?.id) G().state.inventory = r.inventory;
      G().log(`${r.recipient.name} takes ${r.item}${r.moved > 1 ? ' ×' + r.moved : ''}.`, 'success');
      renderPartyRail();
      await reopenSheet();
    } catch (e) {
      G().showError(e.message);
    }
  }

  /**
   * Redraw the open sheet after something on it changed.
   *
   * THE ID IS READ BEFORE THE CLOSE, and that is the whole of this function.
   * `closeTopModal()` runs the sheet's `onClose` synchronously, and that
   * handler sets `sheetCharacterId` to null — so closing and then reopening
   * from the field reopened `character_id=null`, which the API refuses with
   * "character_id required". The sheet vanished and an error came up instead,
   * every time an item was equipped or drunk from it.
   *
   * The guard in `onClose` is right and stays: it exists so that clicking a
   * second party card SWAPS the sheet, where the new id is set before the
   * outgoing sheet closes. What was wrong was reading a field across a call
   * that is entitled to clear it.
   */
  async function reopenSheet() {
    const id = sheetCharacterId;
    if (!id) return;
    closeTopModal();
    await openCharacterSheet(id);
  }

  async function equipItem(inventoryId, equip, characterId) {
    try {
      const r = await API.post('inventory/equip', {
        inventory_id: inventoryId,
        equip,
        character_id: characterId || sheetCharacterId || G().state.character.id,
      });
      G().mergeCharacter(r.character);
      if (r.character?.id == G().state.character?.id) G().state.inventory = r.inventory;
      G().log(equip ? 'Equipped item.' : 'Unequipped item.');
      renderPartyRail();
      await reopenSheet();
    } catch (e) {
      G().showError(e.message);
    }
  }

  async function useItem(inventoryId, characterId) {
    try {
      const r = await API.post('inventory/use', {
        inventory_id: inventoryId,
        character_id: characterId || sheetCharacterId || G().state.character.id,
      });
      G().mergeCharacter(r.character);
      if (r.character?.id == G().state.character?.id) G().state.inventory = r.inventory;
      G().log(r.message, 'success');
      renderPartyRail();
      await reopenSheet();
    } catch (e) {
      G().showError(e.message);
    }
  }

  // =========================================================================
  // Shop
  // =========================================================================

  /**
   * The merchant's stock.
   *
   * Pushed as a modal rather than swapped in, so a shop opened from a
   * conversation leaves the conversation standing behind it and closing the
   * shop returns to the line that offered it.
   */
  async function openShop(npcKey) {
    if (!npcKey) {
      // No merchant, no shop — the old zero-argument call sold a global
      // catalog that included the quest items at 0 gp.
      G().showError('There is no shop here.');
      return;
    }
    await refreshShop(npcKey);
  }

  /**
   * Fetch and (re)draw one merchant's shop.
   *
   * Refetched after every transaction rather than patched in place: stock,
   * gold and the sell list all changed hands, and the server's answer is the
   * only one that matters.
   */
  async function refreshShop(npcKey) {
    let r;
    try {
      r = await API.get('inventory/shop', { npc: npcKey });
    } catch (e) {
      G().showError(e.message);
      return;
    }
    drawShop(npcKey, r.items || [], r.sellables || []);
  }

  function drawShop(npcKey, items, sellables) {
    const gold = G().state.character?.gold ?? 0;
    const stockLabel = (it) =>
      it.stock === null || it.stock === undefined ? '' : ` <span class="help-hint">(${esc(it.stock)} left)</span>`;
    const el = showModal(`
      <h2>Merchant</h2>
      <p class="help-hint">Your gold: <strong>${esc(gold)} gp</strong></p>
      <div class="sheet-section"><h3>For sale</h3>
        <div class="shop-list">
          ${items.length ? items.map((it) => `<div class="shop-item">
            <strong>${esc(it.name)}</strong> — ${esc(it.price)} gp${stockLabel(it)}
            <p>${esc(it.description || it.item_type)}</p>
            <button type="button" class="btn btn-small" data-buy="${esc(it.id)}"
              ${+it.price > gold ? 'disabled title="Not enough gold"' : ''}
              ${it.stock !== null && it.stock !== undefined && +it.stock <= 0 ? 'disabled title="Sold out"' : ''}>Buy</button>
          </div>`).join('') : '<p class="help-hint">Nothing on the shelves.</p>'}
        </div>
      </div>
      <div class="sheet-section"><h3>Sell from your pack</h3>
        <div class="shop-list">
          ${sellables.length ? sellables.map((s) => `<div class="shop-item">
            <strong>${esc(s.name)}</strong>${+s.quantity > 1 ? ` ×${esc(s.quantity)}` : ''}
            — pays ${esc(s.sale_price)} gp
            <button type="button" class="btn btn-small" data-sell="${esc(s.inventory_id)}">Sell</button>
          </div>`).join('') : '<p class="help-hint">Nothing a merchant would pay for.</p>'}
        </div>
      </div>
      <div class="modal-actions"><button type="button" class="btn" data-close>Close</button></div>
    `, { size: 'wide', label: 'Merchant', slot: 'shop' });

    el.querySelector('[data-close]').onclick = closeTopModal;
    const transact = async (route, payload) => {
      try {
        const r = await API.post(route, { ...payload, character_id: G().state.character.id });
        G().log(r.message, 'success');
        G().mergeCharacter(r.character);
        G().state.inventory = r.inventory;
        renderPartyRail();
        await refreshShop(npcKey);
      } catch (e) {
        G().showError(e.message);
      }
    };
    $$('[data-buy]', el).forEach((b) => {
      b.onclick = () => transact('inventory/buy', { item_id: +b.dataset.buy, npc_key: npcKey });
    });
    $$('[data-sell]', el).forEach((b) => {
      b.onclick = () => transact('inventory/sell', { inventory_id: +b.dataset.sell });
    });
  }

  function init() {
    initLogControls();
    redrawLog();
  }

  window.UI = {
    init,
    // overlays
    pushOverlay, overlayOpen,
    showModal, closeTopModal, closeAllModals, confirm,
    // rails
    renderPartyRail, renderQuestTracker, renderActionBar,
    appendLogLine, redrawLog, refreshRolls,
    // panels
    openCharacterSheet, openJournal, openCamp, openShop, openRolls,
    approvalBand,
  };
})();
