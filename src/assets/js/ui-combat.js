/**
 * The combat board: four columns, with the battlefield in the middle two.
 *
 * Your party's cards on the left, the enemy's on the right, and between them
 * the tactical map that ui-battlemap.js draws. The cards carry the detail — hit
 * points, conditions, boons, whose turn it is — and the map carries the one
 * thing cards cannot show, which is where everybody is standing.
 *
 * THE CARDS ARE UNCHANGED FROM THE RANK BOARD, on purpose. `.cbt-card` keeps
 * its width, its three visual channels and its `data-cid`, so playClip(),
 * flash(), float() and stepHp() all still find what they are looking for and
 * every animation the old board had survives untouched. Rewriting the cards to
 * live on the map would have meant rewriting all of that as well, for tokens
 * too small to read a condition pip on.
 *
 * WHAT LEFT THIS FILE. Three rules used to be mirrored here in JavaScript —
 * legalTargets, the rank capacity, which rank was the other one — each with a
 * comment conceding that a copy of a server rule drifts. They are gone. The
 * server sends `state.ui`: which cells this actor can reach and what walking
 * there would cost, which cells the enemy threatens, and the quality of every
 * shot available. The board paints that and computes nothing, so the highlight
 * and the rule are the same code.
 *
 * Two things this file has always done that are worth keeping in mind:
 *
 *   - Turn order comes from `state.order`, drawn as the ribbon along the top.
 *   - Animations are driven by `state.events`, never by reading the English
 *     log. Rewording a log line cannot break an effect.
 */
(function () {
  const G = () => window.Game;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => window.Game.esc(s);

  /** Milliseconds a frame of a combat clip is held. */
  const CLIP_FRAME_MS = 130;

  /**
   * Which glyph a bonus-action slot wears.
   *
   * Keyed by feature, or by feature and option where the feature offers a
   * choice — Cunning Action's two halves are Dash and Disengage and should wear
   * the same icons the actions of those names do. They are told apart by the
   * slot's border rather than by the drawing, because they genuinely are the
   * same manoeuvre bought with a different part of the turn.
   */
  const BONUS_ICONS = {
    rage: 'i-rage',
    second_wind: 'i-heart',
    'cunning_action:dash': 'i-dash',
    'cunning_action:disengage': 'i-disengage',
  };

  /**
   * Slots that arm before they fire.
   *
   * Every other action on the bar has a step between the click and the spending:
   * Attack, Grapple, Help and the rest refuse to fire without a target selected,
   * and Cast and Item open a picker that can be closed. These have neither —
   * they commit the instant they are clicked, and spend an action, a bonus
   * action or a daily use doing it. There is no undo in a fight, so a stray
   * click on Dodge cost the whole turn.
   *
   * One click arms the slot, the second commits it, and anything else puts it
   * back: a different slot, a few seconds' pause, or the bar redrawing under it.
   * Attack is deliberately NOT here — it is the verb the bar exists for, and
   * making the common case cost two clicks to save the rare one is a bad trade.
   */
  const ARM_FIRST = new Set([
    'dash', 'dodge', 'disengage', 'surge', 'channel', 'divine_sense', 'bonus',
  ]);

  /** How long an armed slot waits for its second click before forgetting. */
  const ARM_MS = 3000;

  /** The pending disarm, so that arming a second slot cancels the first's. */
  let armTimer = null;

  const ui = {
    selected: null,   // cid the player is pointing at
    hovered: null,    // cid under the cursor, for the inspector
    clips: null,      // assets/images/combat/index.json, or {} if unavailable
    sheets: new Map(),// character_id → {character, inventory, spells}, cleared per action
    busy: false,
    aiQueued: false,  // a monster turn is already on its way
    // Whether the player throws their own attack dice. Per fight rather than
    // saved: wanting to roll the boss yourself does not mean wanting to roll
    // every rat, and "automate the rest of this fight" is the escape hatch.
    manualRolls: false,
    // A spell with an area, waiting for the cell to put it on:
    // {spell_id, shape, ft, name}. Cleared on cast or on Escape.
    placing: null,
  };

  function root() {
    return $('#combat-root');
  }

  function st() {
    return G().state.combat?.state || null;
  }

  // =========================================================================
  // Reading the server's answers
  //
  // Everything spatial arrives in `state.ui`, computed by the engine. Nothing
  // below decides anything; it only asks.
  // =========================================================================

  function hasCondition(c, key) {
    return (c.conditions || []).some((x) => (typeof x === 'string' ? x : x.key) === key);
  }

  /** CombatEngine::isTargetable — a downed PC is dying, not dead, and shields nobody. */
  function isTargetable(c) {
    return !!c.alive && !hasCondition(c, 'unconscious');
  }

  /**
   * The derived block for whoever is acting, or null.
   *
   * Checked against the current actor rather than taken on trust: the engine
   * computes it only on a party member's turn, so during the monsters' turns
   * the newest one on the state belongs to somebody else.
   */
  function uiFor(actor) {
    const s = st();
    if (!s || !s.ui || !actor || s.ui.actor !== actor.cid) return null;
    return s.ui;
  }

  /**
   * An empty PHP array encodes as `[]` and a populated one as an object.
   *
   * That is a fact about json_encode rather than a decision, and it round-trips
   * through the session blob, so both shapes genuinely arrive. Everything that
   * reads `ui.targets`, `ui.threat` or `ui.reach` goes through here.
   */
  function map(x) {
    return (x && !Array.isArray(x)) ? x : {};
  }

  /**
   * The rules tables, fetched once from `meta/rules`.
   *
   * Slot counts and bonus actions were both transcribed into this file, each
   * with a comment conceding it was a copy. The failure mode is silent — a
   * caster shown four dots when the server will allow three, or a bonus action
   * offered that the engine then refuses — so they are served now and Rules is
   * the only place either exists.
   */
  let rules = null;

  async function loadRules() {
    if (rules) return rules;
    try {
      const r = await API.get('meta/rules');
      rules = {
        slots: r.slots || {},
        bonusActions: r.bonus_actions || {},
        conditions: r.conditions || {},
      };
    } catch (_) {
      // A fight is still playable without them: no slot dots and no bonus
      // action button beats no combat screen.
      rules = { slots: {}, bonusActions: {}, conditions: {} };
    }
    return rules;
  }

  function slotTable(className, level) {
    const lvl = Math.max(1, parseInt(level, 10) || 1);
    const byLevel = rules?.slots?.[className];
    return (byLevel && (byLevel[lvl] || byLevel[String(lvl)])) || { 1: 0, 2: 0, 3: 0 };
  }

  function slotsExpended(character) {
    if (!character) return {};
    const raw = character.spell_slots_json;
    if (!raw) return {};
    try {
      return (typeof raw === 'string' ? JSON.parse(raw) : raw) || {};
    } catch (_) {
      return {};
    }
  }

  // =========================================================================
  // Art
  // =========================================================================

  /**
   * The art key for a combatant.
   *
   * Party members carry a sprite_key. Seeded monsters do not — `monsters` has
   * the column but seed_data.sql never fills it — so the key is recovered from
   * the basename of image_url, which is where slice_assets.py put it.
   */
  function artKey(c) {
    if (c.sprite_key) return c.sprite_key;
    if (c.type === 'pc') {
      const pc = (G().state.party || []).find((p) => p && p.id == c.character_id);
      if (pc?.sprite_key) return pc.sprite_key;
    }
    const m = /([^/]+)\.(png|svg)/i.exec(String(c.image_url || ''));
    return m ? m[1] : 'fighter';
  }

  function faceUrl(c) {
    const key = artKey(c);
    const dir = c.type === 'pc' ? 'npcs' : 'monsters';
    return `assets/images/${dir}/${key}_face.png?v=${G().TILE_CACHE_VER}`;
  }

  /**
   * Full-height battle art for the inspector.
   *
   * Only nine monsters have a battler cut — the bandit is a Townfolk sprite and
   * the pack ships no battle art for it — so the bust is the fallback rather
   * than a broken image.
   */
  function battlerUrl(c) {
    const key = artKey(c);
    if (c.type === 'pc') return `assets/images/npcs/${key}_bust.png?v=${G().TILE_CACHE_VER}`;
    return `assets/images/battlers/${key}.png?v=${G().TILE_CACHE_VER}`;
  }

  function bustFallbackUrl(c) {
    const dir = c.type === 'pc' ? 'npcs' : 'monsters';
    return `assets/images/${dir}/${artKey(c)}_bust.png?v=${G().TILE_CACHE_VER}`;
  }

  /**
   * Load the combat clip index once.
   *
   * Same schema as anim/index.json, for the same reason: a strip cannot say how
   * wide one of its frames is, so the slicer records it at cut time. Failure is
   * survivable — the board simply does not play clips.
   */
  async function loadClips() {
    if (ui.clips) return ui.clips;
    try {
      const res = await fetch(`assets/images/combat/index.json?v=${G().TILE_CACHE_VER}`);
      ui.clips = await res.json();
    } catch (_) {
      ui.clips = {};
    }
    return ui.clips;
  }

  /**
   * Play one 3-frame pose over a combatant's card.
   *
   * A one-frame window with the strip slid through it under `steps(N)`: -100%
   * of the strip is exactly N frame widths, so steps(N) samples it at k/N and
   * lands on -k frames precisely. A percentage `background-position` would land
   * on the wrong offsets — the trap the README describes for the walk sheets.
   */
  function playClip(cid, clip) {
    if (!ui.clips || G().reducedMotion()) return 0;
    const card = cardFor(cid);
    const c = combatant(cid);
    if (!card || !c) return 0;
    const name = `${artKey(c)}_${clip}`;
    const meta = ui.clips[name];
    if (!meta) return 0;

    const frames = meta.frames || 3;
    const ms = frames * CLIP_FRAME_MS;
    const box = document.createElement('div');
    box.className = 'clip-window';
    box.style.width = meta.w + 'px';
    box.style.height = meta.h + 'px';
    box.innerHTML = `<img class="clip-strip" alt=""
      src="assets/images/combat/${encodeURIComponent(name)}.png?v=${G().TILE_CACHE_VER}"
      style="width:${meta.w * frames}px;height:${meta.h}px;
             animation-duration:${ms}ms;animation-timing-function:steps(${frames})">`;
    card.appendChild(box);
    setTimeout(() => box.remove(), ms + 40);
    return ms;
  }

  // =========================================================================
  // Reading the state
  // =========================================================================

  function combatant(cid) {
    return (st()?.combatants || []).find((c) => c.cid === cid) || null;
  }

  function currentActor() {
    const s = st();
    if (!s) return null;
    return combatant(s.order?.[s.turn_index]);
  }

  function cardFor(cid) {
    return root().querySelector(`.cbt-card[data-cid="${cssEscape(cid)}"]`);
  }

  function cssEscape(s) {
    return String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  /**
   * The area a spell covers, if it has one, from its printed range.
   *
   * This is not a rule — the server parses the same string and decides which
   * cells the template actually covers. All the board needs to know is whether
   * casting this one should ask for a spot on the ground rather than a body,
   * and `range_text` is already on the sheet.
   */
  function spellArea(spell) {
    const m = /\((\d+)\s*-?\s*(?:foot|feet|ft\.?)\s*(cone|radius|sphere|cube|line)\)/i
      .exec(String(spell?.range_text || ''));
    return m ? { ft: parseInt(m[1], 10), shape: m[2].toLowerCase() } : null;
  }

  /**
   * The acting character's sheet, for spells, slots and potions.
   *
   * `character/get` only ever describes the session's active character, and the
   * turn can belong to any party member, so the sheet of whoever is acting is
   * fetched on demand. Cleared after every action because slots are spent and
   * potions are drunk.
   */
  async function ensureSheet(actor) {
    if (!actor || actor.type !== 'pc') return null;
    const id = Number(actor.character_id);
    if (ui.sheets.has(id)) return ui.sheets.get(id);
    try {
      const r = await API.get('character/sheet&character_id=' + encodeURIComponent(id));
      ui.sheets.set(id, r);
      return r;
    } catch (_) {
      ui.sheets.set(id, null);
      return null;
    }
  }

  // =========================================================================
  // Drawing
  // =========================================================================

  function close() {
    const el = root();
    if (el && !el.classList.contains('hidden')) {
      el.classList.add('hidden');
      el.innerHTML = '';
    }
    // Neither the inspector nor the top line is inside the board, so emptying
    // the board does not empty them. CSS hides both out of combat either way;
    // this is so the next fight does not open showing the last one's initiative
    // order and whoever died in it.
    const box = document.getElementById('cbt-inspector');
    if (box) {
      box.innerHTML = '';
      box.classList.add('is-idle');
    }
    const top = document.getElementById('cbt-top');
    if (top) top.innerHTML = '';
    // And the camera, so the next encounter opens on the whole board rather
    // than wherever the last one was being watched from.
    if (window.BattleMap) window.BattleMap.forget();
    ui.hovered = null;
    ui.selected = null;
  }

  function render() {
    const s = st();
    const el = root();
    if (!s || !el) return;
    el.classList.remove('hidden');

    const actor = currentActor();
    const combatants = s.combatants || [];

    // Drop a selection that is no longer a body. `alive` and not `isTargetable`
    // on purpose: a downed party member is still a legal target for a healing
    // spell, and deselecting them is how you make reviving somebody fiddly.
    if (ui.selected && !combatant(ui.selected)?.alive) ui.selected = null;

    // Who this actor can actually hit, straight from the engine.
    const legal = new Set(
      s.status === 'active' ? Object.keys(map(uiFor(actor)?.targets)) : []
    );

    renderTop(s, actor);

    el.innerHTML = `
      <div class="cbt-field">
        ${rosterHtml('party', 'Your side', combatants, actor, legal)}
        <div class="cbt-map" id="cbt-map">
          ${window.BattleMap ? window.BattleMap.html(s, { selected: ui.selected, faceUrl }) : ''}
          <div class="map-zoom bm-zoom" role="group" aria-label="Zoom">
            <button type="button" class="icon-btn icon-btn-sm" data-zoom="out"
                    title="Zoom out (−)" aria-label="Zoom out">−</button>
            <button type="button" class="icon-btn icon-btn-sm" data-zoom="reset"
                    title="Fit the whole board (0)" aria-label="Fit the whole board">⌂</button>
            <button type="button" class="icon-btn icon-btn-sm" data-zoom="in"
                    title="Zoom in (+) · hold Space or the middle button to drag"
                    aria-label="Zoom in">+</button>
          </div>
          ${ui.placing ? `<div class="cbt-placing">Placing <strong>${esc(ui.placing.name)}</strong>
            — click the ground. <button type="button" class="btn btn-small" data-cancel-place>Cancel</button></div>` : ''}
        </div>
        ${rosterHtml('foe', 'The enemy', combatants, actor, legal)}
      </div>

      <div class="cbt-bar" id="cbt-bar"></div>`;

    bindCards();
    bindMap(s, actor);
    renderInspector();
    renderBar(s, actor);

    // The spell list, the slots and the potions belong to the acting character
    // and none of them are in combat state, so the sheet is fetched the first
    // time that character's turn comes round and the bar redrawn once it lands.
    // ensureSheet always writes the cache, including on failure, so this cannot
    // loop.
    if (actor && actor.type === 'pc' && !ui.sheets.has(Number(actor.character_id))) {
      ensureSheet(actor).then(() => render());
    }
  }

  /**
   * The fight's top line: turn order, and whose turn it is.
   *
   * Drawn into `#cbt-top`, which lives in the shell (`game.php`) and NOT in
   * `#combat-root` — so it spans the board's column and the log's rather than
   * being confined to the first. The ribbon is as long as the fight has
   * combatants, and in one column it scrolled while 300px of rail sat beside it
   * holding nothing; across the whole width, an eleven-body fight fits.
   *
   * Same arrangement as the inspector: outside the board, looked up in the
   * document, and emptied by close() because wiping the board no longer wipes
   * it.
   */
  function renderTop(s, actor) {
    const box = document.getElementById('cbt-top');
    if (!box) return;
    box.innerHTML = `
      <div class="cbt-ribbon" role="list" aria-label="Initiative order">
        ${(s.order || []).map((cid, i) => ribbonEntry(cid, i === s.turn_index)).join('')}
      </div>

      <div class="cbt-head">
        <strong>${esc(s.encounter_name || 'Combat')}</strong>
        <span class="cbt-round">Round ${esc(s.round)}</span>
        ${actor ? `<span class="cbt-turn">${esc(actor.name)}'s turn</span>` : ''}
        ${movementNote(actor)}
        ${economyHtml(actor)}
      </div>`;
  }

  /**
   * One slot in the initiative ribbon: a token and the roll that put it there.
   *
   * NO NAME UNDER IT. It carried one, truncated from its end so that six
   * bandits arriving as "Thief Captain 1".."Thief Captain 6" could still be
   * told apart — but the line cost this row a third of its height, and the row
   * is charged to the board, which is bound by height and gives back a third as
   * much again in width. The initiative number already tells them apart, being
   * unique in the order by construction, and `aria-label` and the `title` both
   * still say who it is.
   *
   * The roll stays drawn rather than moving into the tooltip with the name: it
   * is the number the ribbon exists to communicate, and a tooltip you have to
   * hunt for is not communicating it.
   */
  function ribbonEntry(cid, isNow) {
    const c = combatant(cid);
    if (!c) return '';
    const down = !isTargetable(c);
    const who = `${c.name} · initiative ${c.initiative}`;
    return `<div class="ribbon-slot ${c.side === 'party' ? 'is-party' : 'is-foe'}
        ${isNow ? 'is-now' : ''} ${down ? 'is-out' : ''}" role="listitem"
        aria-label="${esc(who)}" title="${esc(who)}">
      <span class="ribbon-art">
        <img src="${esc(faceUrl(c))}" alt="">
        ${c.initiative != null ? `<span class="ribbon-init">${esc(c.initiative)}</span>` : ''}
      </span>
    </div>`;
  }

  /** How much walking the actor has left, in the header where it is read. */
  function movementNote(actor) {
    if (!actor || actor.type !== 'pc' || actor.move_left == null) return '';
    const left = Number(actor.move_left) || 0;
    return `<span class="cbt-move ${left ? '' : 'spent'}"
      title="Click a highlighted cell to walk there">${left} ft of movement</span>`;
  }

  /**
   * What is left of the turn: the action, the bonus action, the reaction, and
   * a caster's slots.
   *
   * IN THE HEADER, NOT ON THE BAR. It had a row of its own above the keys,
   * which cost the screen 24px — and the board is bound by height rather than
   * width, so that came off the drawing and took a third as much again off its
   * width with it. The header is one line of text with a screen's worth of
   * empty beside it; this is the same trade `.cbt-top` already made by putting
   * the ribbon and the header on one row.
   *
   * There is no movement pip here any more either. `movementNote()` above says
   * "20 ft of movement" three words to the left, and the bar was printing the
   * same number a second time.
   *
   * `character` is the cached sheet, which is what the slot dots need and which
   * arrives a beat after the turn does; render() runs again when it lands.
   */
  function economyHtml(actor) {
    if (!actor || actor.type !== 'pc') return '';
    const attacksLeft = uiFor(actor)?.attacks_left ?? (actor.action_used ? 0 : 1);
    const character = ui.sheets.get(Number(actor.character_id))?.character;
    return `<span class="cbt-economy">
      <span class="econ ${attacksLeft ? '' : 'spent'}">${
        attacksLeft > 1 ? `Action · ${esc(attacksLeft)} attacks` : 'Action'}</span>
      <span class="econ ${actor.bonus_used ? 'spent' : ''}">Bonus</span>
      ${actor.reaction_used ? '<span class="econ spent">Reaction</span>' : ''}
      ${actor.disengaging ? '<span class="econ is-on" title="Nobody gets a free swing as you leave">Disengaged</span>' : ''}
      <span class="econ-slots">${slotDotsHtml(actor, character)}</span>
    </span>`;
  }

  /**
   * One side's roster, in initiative order.
   *
   * Ordered by `state.order` rather than by rank, which is what the ranks were
   * standing in for: the board no longer has a front and a back to sort into,
   * and initiative is the ordering a player is already reading along the top.
   *
   * The dead are still drawn, greyed and unclickable, until the next action
   * clears them. Two reasons: a body that vanishes the instant it drops leaves
   * the death animation with nothing to play over, and seeing what you have
   * killed is most of how a fight reads.
   */
  function rosterHtml(side, title, combatants, actor, legal) {
    const order = st()?.order || [];
    const rank = (c) => {
      const i = order.indexOf(c.cid);
      return i < 0 ? 99 : i;
    };
    const members = combatants
      .filter((c) => c.side === side)
      .sort((a, b) => rank(a) - rank(b));
    return `<section class="cbt-roster side-${side}" aria-label="${esc(title)}">
      <h3>${esc(title)}</h3>
      ${members.map((c) => cardHtml(c, actor, legal)).join('')
        || '<div class="rank-empty">—</div>'}
    </section>`;
  }

  /**
   * One combatant's card: the portrait, and everything else in `.cbt-body`.
   *
   * The wrapper exists so a party card can put the token beside its readout
   * rather than above it — two boxes make that a `flex-direction`, where eight
   * flat children would have needed a grid and a row span. Foe cards keep the
   * stack; the wrapper is a column there and the card reads exactly as before.
   *
   * Everything the animations resolve — `.cbt-face`, `.hp-bar > span`,
   * `.cbt-hp`, `data-cid` — is still one `querySelector` away from the card, so
   * playClip(), flash(), float() and stepHp() do not care that it moved.
   */
  function cardHtml(c, actor, legal) {
    const pct = c.max_hp ? Math.max(0, Math.min(100, Math.round((c.hp / c.max_hp) * 100))) : 0;
    const band = pct > 50 ? 'ok' : pct > 25 ? 'mid' : 'low';
    const isActor = actor && c.cid === actor.cid;
    const dead = !c.alive;
    const downed = hasCondition(c, 'unconscious');
    const reachable = legal.has(c.cid);
    const foe = actor && c.side !== actor.side;
    const shot = map(uiFor(actor)?.targets)[c.cid];

    // Why an enemy cannot be hit is worth saying — "out of reach" and "no line
    // to them" want different answers from the player, and so does "you can
    // hit them, but at disadvantage".
    const note = foe && !dead
      ? (shot
          ? ` · ${shot.dist} ft${shot.why ? ' — ' + shot.why : ''}`
          : ' · out of reach from where you stand')
      : '';

    return `<button type="button" class="cbt-card ${c.side === 'party' ? 'is-party' : 'is-foe'}
        ${isActor ? 'is-actor' : ''} ${ui.selected === c.cid ? 'is-selected' : ''}
        ${dead ? 'is-dead' : ''} ${downed ? 'is-downed' : ''}
        ${foe && reachable ? 'in-reach' : ''} ${foe && !reachable && !dead ? 'behind-line' : ''}"
        data-cid="${esc(c.cid)}" ${dead ? 'disabled' : ''}
        title="${esc(c.name)} — HP ${esc(c.hp)}/${esc(c.max_hp)}, AC ${esc(c.ac)}${esc(note)}">
      <img class="cbt-face" src="${esc(faceUrl(c))}" alt="">
      <span class="cbt-body">
        <span class="cbt-name">${esc(c.name)}</span>
        <span class="hp-bar"><span class="${band}" style="width:${pct}%"></span></span>
        <span class="cbt-nums"><span class="cbt-hp">${esc(c.hp)}/${esc(c.max_hp)}</span> · AC ${esc(c.ac)}</span>
        ${slotPips(c)}
        ${shot ? `<span class="cbt-dist ${shot.mode !== 'normal' ? 'is-' + esc(shot.mode) : ''}">${esc(shot.dist)} ft${
          shot.cover ? ` · +${esc(shot.cover)} cover` : ''}</span>` : ''}
        ${deathPips(c)}
        <span class="cbt-conds">${(c.conditions || []).map(condPip).join('')}${
          (c.boons || []).map(boonPip).join('')}</span>
        ${c.concentrating ? `<span class="pill pill-conc">${esc(c.concentrating.name)}</span>` : ''}
      </span>
    </button>`;
  }

  /**
   * What this caster has left, a pip per slot, a row per level.
   *
   * `c.slots` is CombatEngine::slotsFor() — the levels this class and level
   * actually has, with how many of each are unspent. Nothing here decides any
   * of that: a slot table in JavaScript is the copied-rule mistake this file's
   * header says was taken out of this screen once already, and it would be
   * wrong for a Warlock before it was ever wrong for anybody else.
   *
   * Spent slots are drawn hollow rather than removed, so the row keeps its
   * width and a glance answers "how much has this fight cost me" as well as
   * "what can I still do".
   */
  function slotPips(c) {
    const slots = c.slots || [];
    if (!slots.length) return '';
    const total = slots.reduce((n, s) => n + s.left, 0);
    return `<span class="cbt-slots" title="Spell slots — ${total} left of ${
      slots.reduce((n, s) => n + s.max, 0)}">${slots.map((s) =>
      `<span class="slot-grp ${s.left ? '' : 'is-spent'}"><b>${esc(s.level)}</b>${
        Array.from({ length: s.max }, (_, i) =>
          `<i class="slot-pip ${i < s.left ? 'on' : ''}"></i>`).join('')
      }</span>`).join('')}</span>`;
  }

  /**
   * Three and three, for somebody who is bleeding out.
   *
   * Drawn only while it is live: a character who is up, stable or gone is not
   * counting rounds any more, and six dots that never change would be six dots
   * the player learns to ignore. This is the one thing on the card that is
   * urgent, so it gets its own row rather than a pip among the conditions.
   */
  function deathPips(c) {
    if (c.side !== 'party' || !c.alive) return '';
    const saves = c.death_saves || {};
    const ok = Number(saves.success) || 0;
    const bad = Number(saves.fail) || 0;
    const down = (c.hp | 0) <= 0 && hasCondition(c, 'unconscious');
    if (!down) return '';
    if (c.stable) return '<span class="cbt-death is-stable">stable</span>';

    const dots = (n, cls) => Array.from({ length: 3 },
      (_, i) => `<i class="death-dot ${cls} ${i < n ? 'on' : ''}"></i>`).join('');
    return `<span class="cbt-death" title="Death saves — ${ok} held, ${bad} against">
      ${dots(ok, 'is-ok')}<b>·</b>${dots(bad, 'is-bad')}</span>`;
  }

  /** What a condition is called and what it does, from the served catalogue. */
  function condInfo(key) {
    const c = rules?.conditions?.[key];
    return {
      label: c?.label || (key.charAt(0).toUpperCase() + key.slice(1)),
      description: c?.description || '',
    };
  }

  function condPip(x) {
    const key = typeof x === 'string' ? x : x.key;
    const rounds = typeof x === 'object' && x.rounds ? Number(x.rounds) : 0;
    const info = condInfo(key);
    // The full sentence in the tooltip, not the raw key. A player who can see
    // that something is on them and cannot find out what it does is being told
    // there is a rule and refused the rule.
    const title = `${info.label}${rounds ? ` · ${rounds} round${rounds === 1 ? '' : 's'}` : ''}`
      + (info.description ? ` — ${info.description}` : '');
    return `<span class="pip pip-${esc(key)}" title="${esc(title)}">${esc(key.slice(0, 2).toUpperCase())}${
      rounds ? `<em>${esc(rounds)}</em>` : ''}</span>`;
  }

  /**
   * A numeric buff — Bless, Shield of Faith, Hunter's Mark.
   *
   * These live on the combatant as `boons`, separate from `conditions`, and the
   * board rendered neither the pip nor the number: a player who spent a slot on
   * Bless saw a log line and then nothing, for the whole minute it was running.
   * The fields are the ones CombatEngine::BOON_FIELDS actually reads.
   */
  const BOON_LABELS = {
    ac: (v) => `${v >= 0 ? '+' : ''}${v} AC`,
    attack_bonus_dice: (v) => `+${v} to attacks`,
    save_bonus_dice: (v) => `+${v} to saves`,
    damage_bonus_dice: (v) => `+${v} damage`,
    check_bonus_dice: (v) => `+${v} to checks`,
  };

  function boonEffects(b) {
    return Object.keys(BOON_LABELS)
      .filter((k) => b[k] !== undefined && b[k] !== null && b[k] !== '' && b[k] !== 0)
      .map((k) => BOON_LABELS[k](b[k]));
  }

  function boonPip(b) {
    const fx = boonEffects(b);
    const rounds = Number(b.rounds) || 0;
    const label = b.label || b.key || 'Blessing';
    const title = `${label}${rounds ? ` · ${rounds} round${rounds === 1 ? '' : 's'}` : ''}`
      + (fx.length ? ` — ${fx.join(', ')}` : '');
    return `<span class="pip pip-boon" title="${esc(title)}">${esc(label.slice(0, 2).toUpperCase())}${
      rounds ? `<em>${esc(rounds)}</em>` : ''}</span>`;
  }

  /**
   * Everything currently riding on somebody, spelled out.
   *
   * The inspector listed condition KEYS and nothing else — "restrained" as a
   * word in a box, with no statement of what being restrained costs you. This
   * is the one place on the board with room for the sentence, so it gets the
   * sentence, and the round counter beside it: a debuff with two rounds left is
   * a different decision from one with six.
   */
  function effectRows(c) {
    const rows = [];
    for (const x of (c.conditions || [])) {
      const key = typeof x === 'string' ? x : x.key;
      const rounds = typeof x === 'object' && x.rounds ? Number(x.rounds) : 0;
      const info = condInfo(key);
      rows.push(`<div class="cbt-effect is-cond">
        <div class="cbt-effect-head">
          <span class="pip pip-${esc(key)}">${esc(key.slice(0, 2).toUpperCase())}</span>
          <strong>${esc(info.label)}</strong>
          ${rounds ? `<span class="cbt-effect-rounds">${esc(rounds)} round${rounds === 1 ? '' : 's'}</span>` : ''}
        </div>
        ${info.description ? `<p>${esc(info.description)}</p>` : ''}
      </div>`);
    }
    for (const b of (c.boons || [])) {
      const fx = boonEffects(b);
      const rounds = Number(b.rounds) || 0;
      rows.push(`<div class="cbt-effect is-boon">
        <div class="cbt-effect-head">
          <span class="pip pip-boon">${esc(String(b.label || b.key || '?').slice(0, 2).toUpperCase())}</span>
          <strong>${esc(b.label || b.key)}</strong>
          ${rounds ? `<span class="cbt-effect-rounds">${esc(rounds)} round${rounds === 1 ? '' : 's'}</span>`
            : '<span class="cbt-effect-rounds">while concentrating</span>'}
        </div>
        ${fx.length ? `<p>${esc(fx.join(' · '))}</p>` : ''}
      </div>`);
    }
    if (c.concentrating) {
      rows.push(`<div class="cbt-effect is-conc">
        <div class="cbt-effect-head"><strong>Concentrating on ${esc(c.concentrating.name)}</strong></div>
        <p>Taking damage forces a Constitution save or the spell ends.</p>
      </div>`);
    }
    return rows.length
      ? `<div class="cbt-inspect-conds">${rows.join('')}</div>`
      : '<div class="cbt-inspect-conds help-hint">Nothing on them.</div>';
  }

  function bindCards() {
    $$('.cbt-card', root()).forEach((el) => {
      el.addEventListener('click', () => {
        ui.selected = el.dataset.cid;
        render();
      });
      el.addEventListener('mouseenter', () => {
        ui.hovered = el.dataset.cid;
        renderInspector();
      });
      el.addEventListener('mouseleave', () => {
        ui.hovered = null;
        renderInspector();
      });
      el.addEventListener('focus', () => {
        ui.hovered = el.dataset.cid;
        renderInspector();
      });
    });
  }

  /**
   * Wire the map up.
   *
   * Hovering a walkable cell draws the route the server would take, by walking
   * the predecessor chain it already sent — no request, and no second
   * pathfinder to disagree with the first.
   */
  function bindMap(s, actor) {
    const box = $('#cbt-map', root());
    if (!box || !window.BattleMap) return;

    const cancel = $('[data-cancel-place]', box);
    if (cancel) cancel.onclick = () => { ui.placing = null; render(); };

    // The camera, re-attached to the SVG this render just drew and handed back
    // the view the last one had. Before bind(), because bind()'s click handler
    // asks it whether the pointer was panning.
    const cam = window.BattleMap.controls(box);
    $$('[data-zoom]', box).forEach((b) => {
      b.onclick = () => {
        if (!cam) return;
        if (b.dataset.zoom === 'in') cam.zoomIn();
        else if (b.dataset.zoom === 'out') cam.zoomOut();
        else cam.reset();
      };
    });

    window.BattleMap.bind(box, {
      onToken: (cid) => {
        if (ui.placing) return placeAt(cellOfCid(cid));
        ui.selected = cid;
        render();
      },
      onCell: (x, y) => {
        if (ui.placing) return placeAt([x, y]);
        if (!actor || actor.type !== 'pc' || s.status !== 'active' || ui.busy) return;
        const reach = map(uiFor(actor)?.reach)[`${x},${y}`];
        if (!reach || !reach.end || !reach.cost) return;
        act('move', { x, y });
      },
      onHover: (cid, cell) => {
        const changed = cid !== ui.hovered;
        ui.hovered = cid;
        if (changed) renderInspector();

        if (ui.placing) {
          const from = actor ? [actor.x, actor.y] : [0, 0];
          window.BattleMap.paintAim(box, ui.placing.shape, ui.placing.ft, from, cell);
          return;
        }
        window.BattleMap.paintPath(box, s, cid || !cell ? null : `${cell[0]},${cell[1]}`);
      },
    });
  }

  function cellOfCid(cid) {
    const c = combatant(cid);
    return c && c.x != null ? [c.x, c.y] : null;
  }

  /** Finish a spell that was waiting for somewhere to land. */
  function placeAt(cell) {
    if (!cell || !ui.placing) return;
    const spellId = ui.placing.spell_id;
    const castLevel = ui.placing.cast_level || null;
    ui.placing = null;
    act('cast', { spell_id: spellId, target_cid: ui.selected || '', target_x: cell[0], target_y: cell[1],
                  ...(castLevel ? { cast_level: castLevel } : {}) });
  }

  /**
   * The card under the cursor wins over the one that is selected: it is what
   * the player is asking about right now.
   *
   * The box is in the page's right-hand rail, under the log — NOT inside
   * `#combat-root` — so it is looked up in the document rather than in the
   * board. That is also why nothing here has to survive `render()` wiping the
   * board's innerHTML: it is not in there to be wiped.
   */
  function renderInspector() {
    const box = document.getElementById('cbt-inspector');
    if (!box) return;
    const c = combatant(ui.hovered) || combatant(ui.selected);
    if (!c) {
      box.classList.add('is-idle');
      box.innerHTML = '<p class="help-hint">Point at somebody.</p>';
      return;
    }
    box.classList.remove('is-idle');

    const shot = map(uiFor(currentActor())?.targets)[c.cid];
    box.innerHTML = `
      <img class="cbt-battler" src="${esc(battlerUrl(c))}" alt=""
           onerror="this.onerror=null;this.src='${esc(bustFallbackUrl(c))}'">
      <div class="cbt-inspect-name">${esc(c.name)}</div>
      <div class="cbt-inspect-line">${esc(c.side === 'party' ? 'Your side' : 'Hostile')}${
        c.speed != null ? ` · speed ${esc(c.speed)} ft` : ''}</div>
      <div class="cbt-inspect-stats">
        <span>HP ${esc(c.hp)}/${esc(c.max_hp)}</span>
        <span>AC ${esc(c.ac)}${shot && shot.cover ? ` +${esc(shot.cover)}` : ''}</span>
        ${c.cr != null ? `<span>CR ${esc(c.cr)}</span>` : ''}
      </div>
      ${shot ? `<div class="cbt-inspect-shot ${shot.mode !== 'normal' ? 'is-' + esc(shot.mode) : ''}">
        ${esc(shot.dist)} feet — ${esc(shot.mode === 'normal' ? 'a clean shot' : shot.mode)}
        ${shot.why ? `<em>${esc(shot.why)}</em>` : ''}
      </div>` : ''}
      ${effectRows(c)}`;
  }

  // =========================================================================
  // The action bar
  // =========================================================================

  function renderBar(s, actor) {
    const bar = $('#cbt-bar', root());
    if (!bar) return;

    if (s.status !== 'active') {
      bar.innerHTML = `<div class="cbt-outcome outcome-${esc(s.status)}">
        <strong>${esc(outcomeTitle(s.status))}</strong>
        ${s.status === 'victory'
          ? `<span>+${esc(s.xp_award)} XP · +${esc(s.gold_award)} gp</span>` : ''}
        ${s.status === 'victory' && (s.loot_award || []).length
          ? `<span class="cbt-loot">${s.loot_award.map((d) =>
              esc(d.name) + (d.qty > 1 ? ' ×' + esc(d.qty) : '')).join(' · ')}</span>` : ''}
        <button type="button" class="btn btn-primary" data-dismiss>Continue</button>
      </div>`;
      const b = bar.querySelector('[data-dismiss]');
      b.onclick = () => leaveCombat(s.status);
      b.focus();
      return;
    }

    if (!actor) return;

    if (actor.type !== 'pc') {
      bar.innerHTML = `<div class="cbt-economy"><span class="waiting">${esc(actor.name)} is deciding…</span></div>`;
      // The engine resolves monster turns inside settle(), so this only fires
      // when a fight opens on a monster's initiative. Queued once rather than
      // once per redraw: render() runs several times a turn and a stack of
      // pending `ai` posts would resolve the same turn repeatedly.
      if (!ui.busy && !ui.aiQueued) {
        ui.aiQueued = true;
        setTimeout(() => act('ai'), 350);
      }
      return;
    }

    // "Not asked yet" and "asked, and they have nothing" are different facts,
    // and the bar used to tell the player the second one whenever the first was
    // true. The cache is dropped after every action, so a caster met that claim
    // on every one of their turns for as long as the fetch took: a Ranger with
    // two slots on her sheet, told by the Cast button that she knows no spells.
    const sheetKnown = ui.sheets.has(Number(actor.character_id));
    const sheet = ui.sheets.get(Number(actor.character_id));
    const character = sheet?.character;
    const spells = sheet?.spells || [];
    // Anything drinkable, readable or workable mid-fight: potions, scrolls,
    // and the misc consumables that carry a use_effect (antitoxin, oils).
    const potions = (sheet?.inventory || []).filter((i) =>
      i.item_type === 'potion' || i.item_type === 'scroll'
      || (i.item_type === 'misc' && (i.properties || '').includes('use_effect')));
    const target = combatant(ui.selected);
    const foeSelected = target && target.side !== actor.side && isTargetable(target);
    // Help wants somebody who can still swing — CombatEngine::doHelp refuses an
    // unconscious ally, so the button says so rather than the server doing.
    const allySelected = target && target.side === actor.side && isTargetable(target) && target.cid !== actor.cid;
    // Whether the selected enemy can actually be hit is the server's answer,
    // not a guess from the weapon: reach, sight, cover and range brackets all
    // fold into whether a shot exists at all.
    const shot = map(uiFor(actor)?.targets)[ui.selected];
    // Defaulting to "one if the action is free" rather than to zero: the block
    // is absent for a beat while a manual attack roll is parked, and a bar that
    // greyed Attack out in that moment would look like the turn had ended.
    const attacksLeft = uiFor(actor)?.attacks_left ?? (actor.action_used ? 0 : 1);

    // The bonus action, if this character's class has one. Mirrors
    // CombatEngine::BONUS_ACTIONS -- a class with no entry gets no button
    // rather than a button that the server refuses.
    // A feature that offers a choice gets a button per choice, rather than a
    // button that opens a picker for two things. `options` is absent on Rage
    // and Second Wind, and a feature without it is still one button — this
    // reads CombatEngine::BONUS_ACTIONS and adds no shape of its own.
    /**
     * One slot's worth of held smite, per level the Paladin still has.
     *
     * Slot counts come from the same served rules table `slotDotsHtml` reads,
     * so the dots on the economy row and these buttons cannot disagree about
     * how many are left. A level with nothing left is not drawn at all — a
     * permanently closed slot per empty level would be three dead squares on a
     * six-button bar.
     */
    function smiteButtons(a, sheet) {
      const table = slotTable(a.class, a.level);
      const used = slotsExpended(sheet);
      const held = Number(a.smite) || 0;
      const out = [];
      for (const lvl of Object.keys(table).map(Number).sort((x, y) => x - y)) {
        const left = (table[lvl] || 0) - (parseInt(used[lvl] ?? used[String(lvl)] ?? 0, 10) || 0);
        if (left < 1) continue;
        out.push({
          key: 'smite',
          label: held === lvl ? `Smite ${lvl} ✓` : `Smite ${lvl}`,
          icon: 'i-sense',
          bonus: true,
          extra: { level: lvl },
          ok: held !== lvl,
          why: held === lvl
            ? 'Already held — it goes into the next thing you hit'
            : `Hold a level ${lvl} slot for your next melee hit. `
              + `Spent only if it lands, and worth more against the undead`,
        });
      }
      return out;
    }

    function bonusButton(a) {
        const spec = rules?.bonusActions?.[a.class];
        if (!spec) return [];
        // `bonus_uses_left` is null for a feature with no rest cost — Cunning
        // Action — and those keep the once-per-fight rule. A budgeted feature
        // (Rage, Second Wind) is limited by the budget instead, so it may be
        // taken twice in one long fight if the character has two left.
        const budgeted = a.bonus_uses_left !== null && a.bonus_uses_left !== undefined;
        const left = budgeted ? Number(a.bonus_uses_left) : null;
        const spent = budgeted
            ? left < 1
            : (a.bonus_features_used || []).includes(spec.key);
        // Raging while raging wastes a use and CombatEngine refuses it, so the
        // button says so rather than the server doing.
        const already = spec.key === 'rage' && (a.boons || []).some((b) => b.key === 'rage');
        const ok = !a.bonus_used && !spent && !already;
        const closed = already ? 'Already raging'
            : spent ? (budgeted ? 'Spent — needs a long rest' : 'Already used in this fight')
            : a.bonus_used ? 'Bonus action already spent this turn'
            : null;

        const options = spec.options || [];
        if (!options.length) {
            return [{
                key: 'bonus',
                label: budgeted ? `${spec.label} (${left})` : spec.label,
                icon: BONUS_ICONS[spec.key] || 'i-rage',
                bonus: true,
                // The count a budgeted feature used to carry in its label. It
                // has nowhere to go on an icon, so it goes in the corner of the
                // slot the way a stack size does.
                count: budgeted ? left : null,
                extra: { which: spec.key },
                ok,
                why: closed || spec.hint,
            }];
        }

        return options.map((o) => ({
            key: 'bonus',
            // Named for the feature and the choice both. "Dash" alone would sit
            // beside the Dash action spending a different half of the turn, and
            // the two are worth telling apart at a glance.
            label: `${spec.label.split(' ')[0]}: ${o.label}`,
            icon: BONUS_ICONS[`${spec.key}:${o.key}`] || BONUS_ICONS[spec.key] || 'i-rage',
            bonus: true,
            extra: { which: spec.key, option: o.key },
            ok,
            why: closed || o.hint,
        }));
    }

    const buttons = [
      // `attacks_left` and not `!action_used`: Extra Attack takes a second
      // swing inside an action that is already spent, and it comes off the
      // server's own count rather than a class table mirrored over here.
      { key: 'attack', label: attacksLeft > 1 ? `Attack (${attacksLeft})` : 'Attack',
        icon: 'i-attack',
        ok: attacksLeft > 0 && foeSelected && !!shot,
        why: !attacksLeft ? 'No attacks left this turn'
           : !foeSelected ? 'Select an enemy first'
           : !shot ? 'Nothing you are carrying reaches them from here — walk closer'
           : shot.mode === 'disadvantage' ? `Strike at ${shot.dist} ft — at disadvantage: ${shot.why}`
           : `Strike at ${shot.dist} ft${shot.why ? ' — ' + shot.why : ''}` },
      { key: 'cast', label: 'Cast', icon: 'i-cast', ok: !actor.action_used && spells.length > 0,
        why: !sheetKnown ? 'Reading their spellbook…'
           : spells.length ? 'Choose a spell' : 'Knows no spells' },
      // Fast Hands rides the server's features map: a Thief drinks on the
      // bonus action while it is free, so the slot stays open past the attack.
      { key: 'item', label: 'Item', icon: 'i-potion',
        ok: (!actor.action_used || (actor.features?.fast_hands && !actor.bonus_used)) && potions.length > 0,
        why: !sheetKnown ? 'Checking their pack…'
           : potions.length
             ? (actor.features?.fast_hands ? 'Drink something — fast hands make it a bonus action' : 'Drink something')
             : 'No usable items' },
      // Dash could not exist on the rank board — there was no distance to
      // double. `move_left` is the whole of what it changes, and the reach
      // highlight redraws off the server's recomputed `ui` afterwards.
      { key: 'dash', label: 'Dash', icon: 'i-dash', ok: !actor.action_used,
        why: `Move again — another ${actor.speed ?? 30} ft this turn` },
      { key: 'dodge', label: 'Dodge', icon: 'i-shield', ok: !actor.action_used,
        why: 'Attacks against you have disadvantage' },
      // Disengage stopped being a polite no-op the day the floor arrived.
      { key: 'disengage', label: 'Disengage', icon: 'i-disengage', ok: !actor.action_used,
        why: 'Move without giving anybody a free swing at you' },
      // Grapple and Shove are special melee attacks: they replace one attack of
      // the Attack action, so they spend an attack rather than the action, and
      // a fighter with Extra Attack may swing and then shove.
      { key: 'grapple', label: 'Grapple', icon: 'i-grapple',
        ok: attacksLeft > 0 && foeSelected && !!shot && !!shot.melee,
        why: !foeSelected ? 'Select an enemy first'
           : !shot || !shot.melee ? 'You have to be beside them'
           : 'Athletics against their Athletics or Acrobatics — hold them where they stand' },
      { key: 'shove', label: 'Shove', icon: 'i-shove',
        ok: attacksLeft > 0 && foeSelected && !!shot && !!shot.melee,
        why: !foeSelected ? 'Select an enemy first'
           : !shot || !shot.melee ? 'You have to be beside them'
           : 'Athletics against their Athletics or Acrobatics — put them on the floor' },
      // Class features that are neither an action nor a bonus action. Both are
      // hidden entirely for a class that has not got them: a permanently closed
      // slot on every other character's bar would be noise, and unlike the
      // actions above these are not part of everybody's turn.
      ...(actor.features?.action_surge ? [{
        key: 'surge', label: 'Action Surge', icon: 'i-rage', bonus: true,
        ok: actor.action_used && !(actor.bonus_features_used || []).includes('action_surge'),
        why: (actor.bonus_features_used || []).includes('action_surge')
          ? 'Spent — that needs a rest'
          : !actor.action_used ? 'Take your action first — this is for when it is gone'
          : 'One more action, right now',
      }] : []),
      // Divine Smite: one slot with a Paladin's name on it, held for the next
      // thing they hit. A button per level they actually hold, because "which
      // slot" is the whole decision — a 1st and a 2nd are very different
      // amounts of a limited resource, and offering only the cheapest would
      // make the feature a formality.
      ...(actor.features?.divine_smite ? smiteButtons(actor, character) : []),
      // Channel Divinity's two halves. Turn Undead is offered to every cleric;
      // Preserve Life only to one who has the domain that grants it, which is
      // the same `subclass` the server stamps on the combatant.
      ...(actor.features?.channel_divinity ? [{
        key: 'channel', label: 'Turn Undead', icon: 'i-sense', bonus: true,
        extra: { which: 'turn_undead' },
        ok: !actor.action_used,
        why: 'The undead within thirty feet save or back away for a minute',
      }] : []),
      ...(actor.features?.channel_divinity && actor.features?.disciple_of_life ? [{
        key: 'channel', label: 'Preserve Life', icon: 'i-hands', bonus: true,
        extra: { which: 'preserve_life' },
        ok: !actor.action_used,
        why: `Five hit points per level, spread over the worst hurt nearby`,
      }] : []),
      ...(actor.features?.lay_on_hands ? [{
        key: 'lay_on_hands', label: 'Lay on Hands', icon: 'i-hands',
        ok: !actor.action_used && allySelected,
        why: !allySelected ? 'Select somebody beside you'
          : 'A day\'s worth of healing, spent as needed — and it draws out poison',
      }] : []),
      ...(actor.features?.divine_sense ? [{
        key: 'divine_sense', label: 'Divine Sense', icon: 'i-sense', ok: true,
        why: 'What is in the room that should not be. Costs no action, only a use',
      }] : []),
      // The bonus-action swing, from two light weapons or from being a Monk.
      // `can_offhand` is the server's answer — it depends on what is equipped,
      // which the combatant does not otherwise carry.
      ...(actor.can_offhand ? [{
        key: 'offhand', label: 'Off-hand', icon: 'i-attack', bonus: true,
        ok: !actor.bonus_used && !!actor.attacks_made && foeSelected && !!shot,
        why: actor.bonus_used ? 'Bonus action already spent this turn'
          : !actor.attacks_made ? 'Take the Attack action first'
          : !foeSelected ? 'Select an enemy first'
          : !shot ? 'Nothing reaches them from here'
          : 'One more swing, as a bonus action',
      }] : []),
      ...(actor.features?.reckless_attack ? [{
        key: 'reckless', label: 'Reckless', icon: 'i-rage', bonus: true,
        ok: !actor.attacks_made && !actor.reckless,
        why: actor.reckless ? 'Already swinging wide open'
          : actor.attacks_made ? 'Too late — decide before the first swing'
          : 'Advantage on your melee swings, and on everything swung back at you',
      }] : []),
      { key: 'ready', label: 'Ready', icon: 'i-ready', ok: !actor.action_used,
        why: 'Hold your swing for the first enemy who comes within reach' },
      { key: 'hide', label: 'Hide', icon: 'i-hide', ok: !actor.action_used,
        why: 'Stealth against what they notice — only from behind cover' },
      ...bonusButton(actor),
      { key: 'help', label: 'Help', icon: 'i-help', ok: !actor.action_used && allySelected,
        why: allySelected ? 'Give them advantage on their next attack' : 'Select an ally first' },
      { sep: true },
      { key: 'flee', label: 'Flee', icon: 'i-flee', ok: !actor.action_used && !!s.allow_flee,
        why: s.allow_flee ? `Break off — DC ${s.flee_dc}` : 'There is no way out of this fight' },
      { key: 'parley', label: 'Parley', icon: 'i-parley', ok: !actor.action_used && !!s.allow_parley,
        why: s.allow_parley ? 'Try talking' : 'There is no talking to these' },
      { key: 'end_turn', label: 'End turn', icon: 'i-hourglass', ok: true,
        why: 'Pass to the next in the order' },
    ];

    // One row, and only the keys on it. What the turn has left is in the
    // header — see economyHtml().
    bar.innerHTML = `
      <div class="cbt-actions">
        <label class="cbt-manual" title="Throw your own attack dice, with a prompt to spend Bless or Bardic Inspiration">
          <input type="checkbox" data-manual ${ui.manualRolls ? 'checked' : ''}>
          <span>My dice</span>
        </label>
        ${buttons.map(slotHtml).join('')}
        <div class="act-tip" id="act-tip" role="tooltip" aria-hidden="true"></div>
      </div>`;

    const manual = $('[data-manual]', bar);
    if (manual) manual.onchange = () => { ui.manualRolls = manual.checked; };

    $$('[data-act]', bar).forEach((b) => {
      b.onclick = () => {
        // Arm on the first click, commit on the second — for the slots that
        // would otherwise spend the turn on the way down. See ARM_FIRST.
        if (ARM_FIRST.has(b.dataset.act) && !b.classList.contains('is-armed')) {
          armSlot(b, bar);
          return;
        }
        disarmAll(bar);
        onAction(b.dataset.act, actor, {
          spells,
          potions,
          // A bonus action has to say WHICH one; everything else is unambiguous
          // from the verb alone.
          extra: b.dataset.extra ? JSON.parse(b.dataset.extra) : null,
        });
      };
      // Hover AND focus, because a slot reachable by Tab has to explain itself
      // to somebody who never touches the mouse. `title` is deliberately gone:
      // the browser's own tooltip cannot be styled, cannot say why a thing is
      // closed in a different colour, and arrives a second and a half late.
      b.addEventListener('mouseenter', () => showTip(b));
      b.addEventListener('focus', () => showTip(b));
      b.addEventListener('mouseleave', hideTip);
      b.addEventListener('blur', hideTip);
    });
  }

  /**
   * One slot on the bar.
   *
   * The name is not drawn — that is the whole point of an icon bar — so it
   * travels in `data-label` for the tooltip and in `aria-label` for anything
   * reading the page aloud. Those two must not drift apart, so they are written
   * from the same string.
   */
  function slotHtml(b) {
    if (b.sep) return '<span class="act-sep" aria-hidden="true"></span>';
    const cls = ['act-slot', b.bonus ? 'is-bonus' : '', b.ok ? '' : 'is-closed'].filter(Boolean).join(' ');
    return `<button type="button" class="${cls}" data-act="${esc(b.key)}"
      ${b.extra ? `data-extra="${esc(JSON.stringify(b.extra))}"` : ''}
      ${b.ok ? '' : 'disabled'}
      data-label="${esc(b.label)}" data-why="${esc(b.why)}" aria-label="${esc(b.label)}">
      <svg class="act-icon" aria-hidden="true"><use href="#${esc(b.icon || 'i-attack')}"></use></svg>
      ${b.count != null ? `<span class="act-count" aria-hidden="true">${esc(b.count)}</span>` : ''}
    </button>`;
  }

  /**
   * The tooltip, as one node moved about rather than one per slot.
   *
   * Positioned in script because pure CSS cannot keep a centred panel inside
   * the screen: the leftmost slot's tooltip would hang off the edge of the
   * board and the rightmost would push the page sideways. Clamped to the bar it
   * belongs to, which is the only element whose width is known here.
   */
  function showTip(btn) {
    const tip = $('#act-tip', root());
    if (!tip) return;
    const why = btn.dataset.why || '';
    tip.innerHTML = `<span class="act-tip-name">${esc(btn.dataset.label || '')}</span>
      ${why ? `<span class="act-tip-why ${btn.disabled ? 'is-blocked' : ''}">${esc(why)}</span>` : ''}`;
    tip.classList.add('is-on');
    tip.setAttribute('aria-hidden', 'false');

    // Measured after the text is in, or the width is the previous slot's.
    const box = tip.parentElement.getBoundingClientRect();
    const at = btn.getBoundingClientRect();
    const want = at.left - box.left + at.width / 2 - tip.offsetWidth / 2;
    tip.style.left = `${Math.max(0, Math.min(want, box.width - tip.offsetWidth))}px`;
  }

  function hideTip() {
    const tip = $('#act-tip', root());
    if (!tip) return;
    tip.classList.remove('is-on');
    tip.setAttribute('aria-hidden', 'true');
  }

  /**
   * Hold a slot one click short of spending it. See ARM_FIRST.
   *
   * The tooltip is refreshed rather than left to the next hover: the pointer is
   * already sitting on the slot that was just clicked, so no `mouseenter` is
   * coming and the tip would otherwise go on offering the reason to press a
   * button that is now asking a question. `why` and the label are swapped rather
   * than rewritten, so disarming restores exactly what slotHtml wrote.
   */
  function armSlot(btn, scope) {
    disarmAll(scope);
    btn.dataset.whyWas = btn.dataset.why || '';
    btn.dataset.why = 'Click again to confirm — this spends it';
    btn.setAttribute('aria-label', `${btn.dataset.label || ''} — click again to confirm`);
    btn.classList.add('is-armed');
    showTip(btn);
    armTimer = setTimeout(() => disarmSlot(btn), ARM_MS);
  }

  function disarmSlot(btn) {
    if (!btn || !btn.classList.contains('is-armed')) return;
    btn.classList.remove('is-armed');
    btn.dataset.why = btn.dataset.whyWas || '';
    delete btn.dataset.whyWas;
    btn.setAttribute('aria-label', btn.dataset.label || '');
  }

  function disarmAll(scope) {
    clearTimeout(armTimer);
    armTimer = null;
    $$('.act-slot.is-armed', scope || root()).forEach(disarmSlot);
  }

  function outcomeTitle(status) {
    if (status === 'victory') return 'Victory!';
    if (status === 'defeat') return 'Defeat…';
    if (status === 'fled') return 'You broke off.';
    if (status === 'parley') return 'Weapons lower.';
    return 'The fight is over.';
  }

  function slotDotsHtml(actor, character) {
    const table = slotTable(actor.class, actor.level);
    const used = slotsExpended(character);
    const out = [];
    // Driven by the table rather than a hardcoded 1..3. Rules::MAX_SLOT_LEVEL
    // is 3 today, so the old bound hid nothing — but it was a second copy of a
    // rules constant living in the client, which is exactly how the slot table
    // drifted the last time, and raising the cap server-side would have
    // silently kept the new slots off the bar.
    const levels = Object.keys(table).map(Number).filter((n) => n > 0).sort((a, b) => a - b);
    for (const lvl of levels) {
      const have = table[lvl] || 0;
      if (!have) continue;
      const spent = parseInt(used[lvl] ?? used[String(lvl)] ?? 0, 10) || 0;
      const dots = [];
      for (let i = 0; i < have; i++) dots.push(`<i class="slot-dot ${i < spent ? 'spent' : ''}"></i>`);
      out.push(`<span class="slot-group" title="Level ${lvl} slots: ${have - spent} of ${have} left">
        <b>${lvl}</b>${dots.join('')}</span>`);
    }
    return out.join('');
  }

  // =========================================================================
  // Doing things
  // =========================================================================

  async function onAction(key, actor, ctx) {
    if (key === 'cast') return openSpellPicker(actor, ctx.spells);
    if (key === 'item') return openItemPicker(actor, ctx.potions);
    if (key === 'attack') return act('attack', { target_cid: ui.selected, manual: ui.manualRolls });
    if (key === 'help') return act('help', { target_cid: ui.selected });
    if (key === 'grapple' || key === 'shove') return act(key, { target_cid: ui.selected });
    if (key === 'offhand') return act('offhand', { target_cid: ui.selected });
    if (key === 'lay_on_hands') return act('lay_on_hands', { target_cid: ui.selected });
    return act(key, ctx.extra || {});
  }

  /**
   * The spell picker.
   *
   * Slot cost is on every row and an unaffordable spell is greyed rather than
   * hidden: a wizard who is out of second-level slots should be able to see
   * that that is why, without counting dots.
   */
  function openSpellPicker(actor, spells) {
    const sheet = ui.sheets.get(Number(actor.character_id));
    const table = slotTable(actor.class, actor.level);
    const used = slotsExpended(sheet?.character);
    const left = (lvl) => (table[lvl] || 0) - (parseInt(used[lvl] ?? used[String(lvl)] ?? 0, 10) || 0);

    const rows = spells.map((sp) => {
      // A reaction spell casts itself when its trigger lands; the server
      // refuses it as an action, so the picker says so instead of offering it.
      if (/reaction/i.test(sp.casting_time || '')) {
        return `<div class="spell-row is-reaction">
          <span class="spell-name">${esc(sp.name)}</span>
          <span class="pill">Reaction</span>
          <span class="spell-note">casts itself when its moment comes</span>
        </div>`;
      }
      const lvl = parseInt(sp.level, 10) || 0;
      // Cantrips cost nothing, which CombatEngine::slotsAvailable answers by
      // always reporting a slot available rather than special-casing them.
      const affordable = lvl === 0 || left(lvl) > 0;
      const needsTarget = (sp.target_kind || 'enemy') !== 'self' && (sp.target_kind || 'enemy') !== 'all_allies';
      const area = spellArea(sp);
      // "At Higher Levels" — a chip per bigger slot still standing. Only for
      // spells whose row carries upcast data; the server clamps and re-checks
      // whatever is sent, these are an offer rather than the rule.
      let upcast = '';
      if (lvl > 0 && sp.higher_level_json) {
        const chips = [];
        for (let l = lvl + 1; l <= 3; l++) {
          if (left(l) > 0) {
            chips.push(`<button type="button" class="spell-upcast-btn" data-spell="${esc(sp.id)}"
              data-cast-level="${l}" ${area ? `data-area="${esc(JSON.stringify(area))}"` : ''}
              >at level ${l} · ${esc(left(l))} left</button>`);
          }
        }
        if (chips.length) upcast = `<div class="spell-upcast">${chips.join('')}</div>`;
      }
      return `<button type="button" class="spell-row" data-spell="${esc(sp.id)}" ${affordable ? '' : 'disabled'}
          ${area ? `data-area="${esc(JSON.stringify(area))}"` : ''}>
        <span class="spell-name">${esc(sp.name)}</span>
        <span class="pill">${lvl === 0 ? 'Cantrip' : 'Level ' + lvl}</span>
        ${lvl > 0 ? `<span class="spell-cost ${affordable ? '' : 'gone'}">${esc(left(lvl))} left</span>` : ''}
        ${sp.range_text ? `<span class="spell-range">${esc(sp.range_text)}</span>` : ''}
        ${sp.damage_dice ? `<span class="spell-dice">${esc(sp.damage_dice)} ${esc(sp.damage_type || '')}</span>` : ''}
        ${area ? '<span class="spell-note">pick a spot on the ground</span>'
          : (needsTarget && !ui.selected ? '<span class="spell-note">no target selected</span>' : '')}
      </button>${upcast}`;
    }).join('');

    const el = window.UI.showModal(`
      <h2>Cast</h2>
      <p class="help-hint">Range is printed on every spell and now means what it says —
        walk closer if it will not carry.</p>
      <div class="spell-list">${rows || '<p class="help-hint">Nothing to cast.</p>'}</div>
      <div class="modal-actions"><button type="button" class="btn" data-close>Back</button></div>
    `, { size: 'wide', label: 'Cast a spell' });

    el.querySelector('[data-close]').onclick = window.UI.closeTopModal;
    $$('[data-spell]', el).forEach((b) => {
      b.onclick = () => {
        window.UI.closeTopModal();
        const area = b.dataset.area ? JSON.parse(b.dataset.area) : null;
        const castLevel = b.dataset.castLevel ? +b.dataset.castLevel : null;
        if (area) {
          // A cone or a blast wants somewhere to go rather than somebody, so
          // the board asks for a cell. Which cells it then covers is the
          // server's answer, and it arrives in the `aoe` event.
          const name = b.closest('.spell-upcast')
            ? (b.closest('.spell-upcast').previousElementSibling
                ?.querySelector('.spell-name')?.textContent || 'the spell')
            : (b.querySelector('.spell-name')?.textContent || 'the spell');
          ui.placing = { spell_id: +b.dataset.spell, shape: area.shape, ft: area.ft, name,
                         cast_level: castLevel };
          render();
          return;
        }
        act('cast', { spell_id: +b.dataset.spell, target_cid: ui.selected || actor.cid,
                      ...(castLevel ? { cast_level: castLevel } : {}) });
      };
    });
  }

  function openItemPicker(actor, potions) {
    const el = window.UI.showModal(`
      <h2>Use an item</h2>
      <div class="spell-list">
        ${potions.map((p) => `<button type="button" class="spell-row" data-item="${esc(p.id)}">
          <span class="spell-name">${esc(p.name)}</span>
          <span class="pill">×${esc(p.quantity)}</span>
          ${p.damage_dice ? `<span class="spell-dice">${esc(p.damage_dice)}</span>` : ''}
        </button>`).join('')}
      </div>
      <div class="modal-actions"><button type="button" class="btn" data-close>Back</button></div>
    `, { label: 'Use an item' });

    el.querySelector('[data-close]').onclick = window.UI.closeTopModal;
    $$('[data-item]', el).forEach((b) => {
      b.onclick = () => {
        window.UI.closeTopModal();
        act('use_item', { inventory_id: +b.dataset.item });
      };
    });
  }

  /**
   * Send one action and play back what it did.
   *
   * The log lines added by this action are printed as its `events` animate,
   * paced by the `log_at` mark each event carries. They remain independent in
   * the way that matters — nothing here reads the prose, which is the point of
   * `events` existing — but they no longer arrive as two separate batches. The
   * engine clears `events` at the start of every action, so whatever comes back
   * is exactly this action's doing.
   */
  async function act(action, extra = {}) {
    if (ui.busy) return;
    ui.busy = true;
    const before = (st()?.log || []).length;
    try {
      const r = await API.post('combat/action', {
        action,
        character_id: G().state.character.id,
        ...extra,
      });
      G().state.combat = r.combat;
      ui.sheets.clear();

      const s = st();

      // The log rides along with the playback rather than preceding it. Printing
      // this action's lines here dropped the whole round's prose in before the
      // first swing animated — "the rat dies" while its bar was still visibly
      // stepping down — which reads as the board lagging the log. playEvents
      // flushes each line as the event it belongs to plays.
      //
      // Deliberately NOT rendered before the playback.
      //
      // render() paints the board at the end of the whole exchange, so calling
      // it first dropped every hit point bar to its final value and left the
      // animation to float numbers over damage that had visibly already
      // happened. Four rats in one round read as one instant deletion. The old
      // board stays up, each blow steps the bar it belongs to, and the real
      // render happens once the dust has settled.
      await playEvents(s?.events || [], s?.log || [], before);

      render();
      // Hit points move in combat state, not on the `characters` rows the rail
      // is otherwise drawn from, so the rail has to be told to look again.
      window.UI.renderPartyRail();

      // The turn is parked on a die the player asked to throw. The ceremony is
      // the same one a dialogue check opens; what comes back finishes the
      // attack, and "automate the rest" switches the fight back to rolling for
      // itself from here on.
      if (s?.pending_check) {
        await runPendingAttackRoll(s.pending_check);
      }

      if (s?.status === 'parley') {
        await openParley(s);
      } else if (s?.status === 'fled') {
        await leaveCombat('fled');
      } else {
        render();
      }
    } catch (e) {
      G().showError(e.message);
    } finally {
      ui.busy = false;
      ui.aiQueued = false;
    }
  }

  async function refreshCombat() {
    const r = await API.get('combat/status');
    G().state.combat = r.combat;
    render();
  }

  /**
   * A fight that became a conversation.
   *
   * `encounters.parley_node` is authored as "npc_key:node_key", which is the
   * only place in the schema two keys share a column — the alternative was two
   * nullable columns that only ever make sense together.
   */
  async function openParley(s) {
    const ev = (s.events || []).find((e) => e.type === 'parley');
    const spec = String(ev?.node || s.parley_node || '');
    await leaveCombat('parley');
    const [npcKey, nodeKey] = spec.split(':');
    if (!npcKey) return;
    try {
      await window.Dialog.openNode(npcKey, nodeKey || null);
    } catch (e) {
      G().showError(e.message);
    }
  }

  async function leaveCombat(status) {
    close();
    G().state.mode = 'explore';
    G().state.combat = null;
    ui.selected = null;
    ui.hovered = null;
    ui.sheets.clear();
    await G().refreshAll();
    if (status === 'defeat') G().log('You wake at the Golden Flagon…', 'danger', 'combat');

    // A won fight is the commonest way to level, and the level was recorded as
    // owed rather than applied — a round is no place to stop and wait on a hit
    // die the player has not thrown. Claim it now the fight is over.
    if (window.LevelUp) await window.LevelUp.checkPending();
  }

  // =========================================================================
  // Animating from state.events
  // =========================================================================

  /**
   * Replay one action's events in order.
   *
   * Driven entirely by the structured list. The old client read the English log
   * with regexes, so "Name hits Target" had to keep saying exactly that or the
   * hit stopped flashing; nothing here reads a word of prose.
   */
  async function playEvents(events, log, from) {
    // The log is printed as the events play rather than in one batch before
    // them. Each event carries `log_at` — how many lines the server's log held
    // when it happened — so flushing up to that mark lands the line describing
    // a blow on the same beat as the blow. Absolute indices on both sides: the
    // server counts into the whole log and `sent` starts at this action's
    // baseline, so the two agree without either doing arithmetic on the other.
    //
    // Optional, because the pending-check replay drives this with events alone.
    const lines = log || [];
    let sent = from || 0;
    const flushTo = (mark) => {
      const to = Math.min(mark == null ? lines.length : mark, lines.length);
      for (; sent < to; sent++) G().log(lines[sent], '', 'combat');
    };

    if (!events.length) {
      // No animation to interleave with, but the prose still has to arrive.
      flushTo(null);
      return;
    }
    await loadClips();
    const reduced = G().reducedMotion();

    for (const ev of events) {
      flushTo(ev.log_at);
      let wait = 0;
      switch (ev.type) {
        case 'cast':
          wait = playClip(ev.actor, 'magic');
          float(ev.actor, ev.spell, 'float-cast');
          break;

        case 'attack':
          if (ev.mode === 'auto') {
            wait = playClip(ev.actor, 'magic');
          } else {
            wait = playClip(ev.actor, 'attack1');
          }
          // A shot crosses the board before it lands. Awaited rather than left
          // to run underneath, so the damage float does not appear over the
          // target while the bolt is still halfway there — the point of the
          // thing is that a swing and a shot no longer look identical.
          if (ev.kind === 'ranged' && !reduced) {
            await shotFlies(ev);
          }
          if (ev.hit) {
            flash(ev.target, ev.crit ? 'crit-flash' : 'hit-flash');
            float(ev.target, '-' + ev.damage, ev.crit ? 'float-crit' : 'float-dmg');
            stepHp(ev.target, ev.target_hp, ev.target_max_hp);
            if (ev.crit) shake();
          } else {
            float(ev.target, 'miss', 'float-miss');
          }
          break;

        case 'save':
          float(ev.actor, `${ev.ability.toUpperCase()} ${ev.total} vs ${ev.dc}`,
            ev.passed ? 'float-save-ok' : 'float-save-bad');
          break;

        // A death save is not an ordinary save and does not read like one: it
        // has no ability behind it and the number that matters is the tally,
        // not the roll. `natural` is 0 when the failure came from being struck
        // while down rather than from a die.
        case 'death_save': {
          const tally = `${ev.success}/${ev.fail}`;
          if (ev.outcome === 'revive') {
            float(ev.target, 'up!', 'float-heal');
          } else if (ev.outcome === 'died') {
            float(ev.target, 'dead', 'float-crit');
          } else if (ev.outcome === 'stable') {
            float(ev.target, 'stable', 'float-save-ok');
          } else {
            float(ev.target, ev.natural ? `death ${ev.natural} · ${tally}` : `death ${tally}`,
              ev.natural >= 10 ? 'float-save-ok' : 'float-save-bad');
          }
          wait = 420;
          break;
        }

        case 'heal':
          flash(ev.target, 'heal-flash');
          float(ev.target, '+' + ev.amount, 'float-heal');
          break;

        case 'condition':
          float(ev.target, (ev.applied ? '' : 'no longer ') + ev.key, 'float-cond');
          break;

        // Every boon the engine grants emitted this and the board played
        // nothing, so Bless and Rage arrived in silence — the pip appeared on
        // the next render with no moment attached to it. The label, not the
        // key: `addBoon` carries one and it is what the pip's tooltip says.
        case 'boon':
          float(ev.target, (ev.applied ? '' : 'no longer ') + (ev.label || ev.key), 'float-boon');
          break;

        // Walking. The token is slid along the path rather than snapped, and
        // the card gets the distance floated over it, because the card is
        // where the player is already looking for numbers.
        case 'move':
          wait = await walkToken(ev);
          break;

        // A contested check — Grapple, Shove, Hide. One float rather than two,
        // over whoever the contest was about, because the pair of numbers is
        // the whole story and two floats would race each other.
        case 'contest':
          float(ev.target || ev.actor,
            `${ev.kind} ${ev.attacker} v ${ev.defender}`,
            ev.won ? 'float-save-ok' : 'float-save-bad');
          wait = 380;
          break;

        // A readied swing landing. The attack event that follows carries the
        // damage; this is the beat that says why it happened out of turn.
        case 'readied':
          float(ev.actor, 'ready!', 'float-cast');
          wait = 260;
          break;

        // Caught unawares — the turn that never happened.
        case 'surprised':
          float(ev.actor, 'surprised', 'float-cond');
          wait = 320;
          break;

        // Emitted before the swing it causes, so the reaction reads as a
        // reaction rather than as part of the mover's own turn.
        case 'opportunity':
          float(ev.actor, 'reaction!', 'float-cond');
          flash(ev.actor, 'hit-flash');
          wait = 260;
          break;

        // The real footprint, from the server. Painted before the saves land
        // so that they land inside something.
        case 'aoe':
          if (window.BattleMap) window.BattleMap.paintAoe($('#cbt-map', root()), ev.cells);
          float(ev.actor, ev.spell, 'float-cast');
          wait = 320;
          break;

        case 'concentration_broken':
          float(ev.actor, `${ev.spell} ends`, 'float-cond');
          break;

        case 'death':
          if (ev.dead) wait = playClip(ev.target, 'dead1');
          flash(ev.target, 'death-flash');
          break;

        default:
          break;
      }
      if (!reduced) await G().sleep(Math.max(180, Math.min(wait, 520)));
    }

    // Anything the engine said after its last event — a death, the round
    // turning over — which has no beat of its own to ride in on.
    flushTo(null);
  }

  /**
   * Move one combatant's bar to where a single blow left it.
   *
   * Only the card on the board is touched, not the state: the authoritative
   * numbers arrive with the response and render() applies them once playback
   * is over. This exists purely so the bar falls on the beat of the hit that
   * caused it, which is the difference between four rats taking a round off
   * somebody and four rats appearing to delete them in one frame.
   */
  /**
   * The turn is parked on a die the player asked to throw.
   *
   * The same ceremony a dialogue check opens, resolving through
   * `combat/resolve_check` so the attack it interrupted finishes in the same
   * round trip — the alternative is two requests with the fight in an
   * unfinished state between them.
   *
   * Cancelling is deliberately not offered a way out: the action is already
   * spent by the time the prompt appears, so walking away would mean an attack
   * that cost a turn and did nothing. The overlay's Esc resolves null, and this
   * rolls anyway with nothing spent.
   */
  async function runPendingAttackRoll(check) {
    let finished = null;
    const outcome = await window.Check.open(check, {
      automateLabel: 'Roll these for me from now on',
      resolve: async (checkId, boosts) => {
        const r = await API.post('combat/resolve_check', { check_id: checkId, boosts });
        finished = r.combat;
        return r.combat.roll;
      },
    });

    if (outcome && outcome.automate) ui.manualRolls = false;

    if (!finished) {
      // The player dismissed the prompt without rolling. The attack is still
      // parked server-side, so ask the engine to settle it unspent rather than
      // leaving the turn stuck.
      const r = await API.post('combat/resolve_check', {
        check_id: check.check_id,
        boosts: [],
      }).catch(() => null);
      finished = r ? r.combat : null;
    }
    if (!finished) return;

    G().state.combat = finished;
    ui.sheets.clear();
    await playEvents(st()?.events || []);
    render();
    window.UI.renderPartyRail();
  }

  /**
   * Slide a token along the path it walked.
   *
   * Cell by cell rather than in one jump, because the interesting thing about a
   * move is the route: whether somebody went round the pillar or straight past
   * the ogre. An opportunity attack is emitted as its own event before the
   * `attack` it causes, and both arrive after this one, so a walk that ended
   * badly still plays out in order.
   */
  async function walkToken(ev) {
    const box = $('#cbt-map', root());
    if (!box || !window.BattleMap) return 0;
    const path = ev.path || [];
    float(ev.actor, `${ev.cost} ft`, 'float-cond');
    if (G().reducedMotion() || path.length < 2) {
      const end = path[path.length - 1] || ev.to;
      if (end) window.BattleMap.moveToken(box, ev.actor, end[0], end[1]);
      return 0;
    }
    for (let i = 1; i < path.length; i++) {
      window.BattleMap.moveToken(box, ev.actor, path[i][0], path[i][1]);
      await G().sleep(70);
    }
    return 0;
  }

  function stepHp(cid, hp, maxHp) {
    if (hp === undefined || !maxHp) return;
    const card = cardFor(cid);
    if (!card) return;
    const fill = card.querySelector('.hp-bar > span');
    if (fill) {
      fill.style.width = G().hpPct(hp, maxHp) + '%';
      fill.className = G().hpBand(hp, maxHp);
    }
    // Only the hit points; the AC sits in the same line and does not change.
    const nums = card.querySelector('.cbt-hp');
    if (nums) nums.textContent = `${hp}/${maxHp}`;
  }

  function flash(cid, cls) {
    const card = cardFor(cid);
    if (!card) return;
    card.classList.add(cls);
    setTimeout(() => card.classList.remove(cls), 520);
  }

  /**
   * Numbers, over whoever they happened to.
   *
   * On the TOKEN, which is where the player is looking during a fight — the
   * board is the thing with the fight on it and the roster is a reference. It
   * used to float over the card only, which meant a hit at the far end of the
   * board announced itself in a column at the edge of the screen.
   *
   * The card is the fallback rather than a second copy. Two floats saying the
   * same number in two places is noise, and the case the fallback exists for is
   * real: a combatant with no cell yet, and the outcome banner's own floats.
   */
  function float(cid, text, cls) {
    const c = combatant(cid);
    const box = $('#cbt-map', root());
    if (box && window.BattleMap && c && c.x != null && c.y != null) {
      window.BattleMap.floatText(box, c.x, c.y, text, cls);
      return;
    }
    const card = cardFor(cid);
    if (!card) return;
    const f = document.createElement('span');
    f.className = 'cbt-float ' + cls;
    f.textContent = text;
    card.appendChild(f);
    setTimeout(() => f.remove(), 1100);
  }

  /**
   * Send the bolt, and wait for it to arrive.
   *
   * `from` and `to` come off the event rather than off the combatants, because
   * by the time this plays the state has already been replaced with the one
   * after the whole action — a monster that walked and then shot would launch
   * its arrow from where it ended up rather than from where it fired.
   */
  function shotFlies(ev) {
    const box = $('#cbt-map', root());
    if (!box || !window.BattleMap || !ev.from || !ev.to) return Promise.resolve();
    const ms = window.BattleMap.shoot(box, ev.from, ev.to, ev.mode === 'auto' ? 'is-spell' : '');
    return new Promise((done) => setTimeout(done, ms || 0));
  }

  function shake() {
    if (G().reducedMotion()) return;
    const el = root();
    el.classList.add('screen-shake');
    setTimeout(() => el.classList.remove('screen-shake'), 300);
  }

  /**
   * The keys people already try on a map, while a fight is on screen.
   *
   * Bound once to the document rather than to the board, because the board is
   * replaced on every action and a listener per render is a listener leaked per
   * render. game.js's own key handler stands down in combat (`state.mode ===
   * 'combat'` returns early there), so nothing here has to fight it — but a
   * conversation, a die prompt or a modal over the top still owns the keyboard.
   */
  function bindKeys() {
    document.addEventListener('keydown', (e) => {
      if (G().isTypingTarget(e.target) || e.ctrlKey || e.metaKey || e.altKey) return;
      if (!st() || root()?.classList.contains('hidden')) return;
      if (document.querySelector('#check-root:not(.hidden)')) return;
      if (document.querySelector('#modal-root:not(.hidden)')) return;
      const cam = window.BattleMap ? window.BattleMap.camera() : null;
      if (!cam) return;
      if (e.key === '+' || e.key === '=') { e.preventDefault(); cam.zoomIn(); }
      else if (e.key === '-' || e.key === '_') { e.preventDefault(); cam.zoomOut(); }
      else if (e.key === '0') { e.preventDefault(); cam.reset(); }
    });
  }

  async function init() {
    // Both fetched once and kept. A clip missing from the index simply does not
    // play rather than 404ing on every swing; the rules tables are awaited
    // because the action bar cannot draw slot dots or a bonus action button
    // without them, and drawing those wrong is worse than drawing them late.
    loadClips();
    await loadRules();
    if (!init.keysBound) { bindKeys(); init.keysBound = true; }
    if (st()) render();
  }

  window.Combat = {
    init, render, close,
    // `legalTargets` used to be exported so the reach rule could be reasoned
    // about from outside the board. It no longer exists here: reach is a
    // distance now, and the only implementation of it is the server's.
    isTargetable, slotTable,
  };
})();
