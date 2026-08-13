/**
 * The game driver.
 *
 * The world is a graph of described scenes, not a grid of tiles. This file owns
 * the state the whole client shares, draws the location screen — prose, the
 * people standing in it, the actions — and hands off to the other modules for
 * everything that already had a screen of its own: combat (ui-combat.js),
 * conversation (ui-dialog.js), the d20 ceremony (ui-check.js), levelling
 * (ui-levelup.js) and the panels (ui-panels.js).
 *
 * Ways out live on the region chart, not as a second list under the scene:
 * the places you can walk to from here are highlighted on the map, with the
 * same number keys the old exit list used. That keeps a crowded street's
 * people on screen instead of buried under five exit buttons.
 *
 * It replaces a 3,200-line tile renderer. Almost none of that was doing work
 * this game needs: a location has no camera, no pathfinder, no chunked terrain
 * streaming and nothing to collide with. What survives is the shared vocabulary
 * those other modules were already written against — `Game.state`, `Game.log`,
 * the portrait-path helpers — because none of that was ever about tiles.
 *
 * Everything the player does here is a menu: click a place on the map, click a
 * person, click an action, or press its key. That is the whole interaction
 * model.
 */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);

  /**
   * Cache-buster for portrait art, kept under its old name because
   * ui-combat.js and ui-dialog.js interpolate `Game.TILE_CACHE_VER` straight
   * into the URLs they build. The tiles it was named for are gone; the faces
   * and busts it also versions are not.
   */
  const TILE_CACHE_VER = 'pv12';   // pv12: Sib Marrell redrawn — he was in mail

  const DEFAULT_SPRITE_KEY = 'fighter';

  /** How long a travel narration line waits before the next, in ms. */
  const STEP_PACE = 260;

  /** How long the party stand at the destination before the chart closes. */
  const ARRIVAL_HOLD = 200;

  const state = {
    mode: 'location',        // 'location' | 'combat'
    character: null,
    party: [],
    partyId: null,
    location: null,          // the scene: prose, people, exits, ambush
    regionMap: null,         // nodes/edges for the SVG chart
    combat: null,
    quests: [],
    questMarkers: [],
    companions: [],
    inventory: [],
    knownNpcIds: [],
    avatars: null,
    log: [],
    busy: false,             // a request is in flight; menus are inert
    // The first-person view of a generated floor. `on` is a preference, and
    // `cursor` is where in the tiles the party is standing — VIEW state, and
    // reconciled against the server's answer by fpSync() on every render. See
    // ui-firstperson.js: the location is the truth and this is a camera.
    fp: { on: false, cursor: null },
  };

  // =========================================================================
  // Small shared utilities. Ported unchanged: the other modules are written
  // against these exact behaviours.
  // =========================================================================

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtMod(m) {
    if (m === undefined || m === null) return '–';
    return (m >= 0 ? '+' : '') + m;
  }

  function hpPct(cur, max) {
    if (!max) return 0;
    return Math.max(0, Math.min(100, Math.round((cur / max) * 100)));
  }

  /**
   * Which colour band an HP bar is in.
   *
   * Lives here rather than at each call site because the party rail, the
   * character sheet and the combat board all draw the same bar, and when only
   * one of them applied a band the other two rendered every character as
   * bleeding out — the bare `.hp-bar > span` fill is red.
   */
  function hpBand(cur, max) {
    const pct = hpPct(cur, max);
    return pct > 50 ? 'ok' : pct > 25 ? 'mid' : 'low';
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  /**
   * Whether the player has asked their OS to stop things moving.
   *
   * Read at call time rather than cached: the setting can change while the tab
   * is open, and a die that keeps spinning because the page was loaded an hour
   * ago is exactly the thing the setting exists to prevent.
   */
  function reducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function isTypingTarget(el) {
    if (!el) return false;
    return /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.isContentEditable;
  }

  /**
   * A line for the adventure log.
   *
   * `cat` is what the Log tab filters on. It is a separate argument from `cls`
   * because the two answer different questions — `cls` is how loud the line is,
   * `cat` is what kind of thing happened — and folding them together would mean
   * every important story beat had to be styled like a critical hit to be
   * findable.
   *
   * The log matters more here than it did in the graphical game: it is the
   * transcript of the playthrough. Travel, arrivals, searches and rests all
   * narrate into it, so scrolling back reads as an account of what happened.
   */
  function log(msg, cls = '', cat = 'story') {
    const line = { msg, cls, cat, t: Date.now() };
    state.log.push(line);
    if (state.log.length > 400) state.log.shift();
    if (window.UI) window.UI.appendLogLine(line);
  }

  let errorTimer = null;

  function showError(msg) {
    const b = $('#error-banner');
    if (!b) return;
    b.textContent = msg;
    b.classList.remove('hidden');
    clearTimeout(errorTimer);
    errorTimer = setTimeout(() => b.classList.add('hidden'), 5000);
  }

  // =========================================================================
  // Portrait paths.
  //
  // The one part of the old art pipeline that survives the pivot. Portraits
  // are kept — the party rail, the dialogue bust, the combat cards and the
  // character sheet all draw them — so these helpers are unchanged, and the
  // modules that call them needed no edit at all.
  // =========================================================================

  function withTileVer(url) {
    if (!url) return url;
    const base = url.replace(/\.svg$/i, '.png');
    return base + (base.includes('?') ? '&' : '?') + 'v=' + TILE_CACHE_VER;
  }

  /** Cache-bust character/monster body art after an art swap. */
  function bodySpriteUrl(url) {
    if (!url) return url;
    let u = String(url).replace(/\.svg$/i, '.png');
    u = u.replace(/[?&]v=[^&]*/g, '');
    u = u.replace(/\?$/, '');
    return u + (u.includes('?') ? '&' : '?') + 'v=' + TILE_CACHE_VER;
  }

  /**
   * Companion files to a standing frame: `foo.png` also has `foo_face.png`
   * and `foo_bust.png`.
   */
  function variantUrl(url, suffix) {
    if (!url) return null;
    const base = String(url).replace(/[?&]v=[^&]*/g, '').replace(/\?$/, '').replace(/\.(svg|png)$/i, '');
    return `${base}${suffix}.png?v=${TILE_CACHE_VER}`;
  }

  function spriteKeyOf(pc) {
    return (pc && pc.sprite_key) || DEFAULT_SPRITE_KEY;
  }

  function spriteArtUrl(key, suffix) {
    return `assets/images/npcs/${key}${suffix}.png?v=${TILE_CACHE_VER}`;
  }

  function classArtUrl(pc, suffix) {
    return spriteArtUrl(spriteKeyOf(pc), suffix);
  }

  /**
   * Cross-fade the scene around a change of place.
   *
   * ui-dialog.js calls this when a conversation moves the party, so it has to
   * keep working. It is now a plain opacity dip on the location panel rather
   * than a filter on a map stage.
   */
  async function mapFadeThrough(fn) {
    const root = $('#location-root');
    if (!root || reducedMotion()) {
      await fn();
      return;
    }
    root.classList.add('is-fading');
    await sleep(140);
    try {
      await fn();
    } finally {
      root.classList.remove('is-fading');
    }
  }

  // =========================================================================
  // State plumbing
  // =========================================================================

  /** Fold an updated character back into the active character and the party. */
  function mergeCharacter(updated) {
    if (!updated) return;
    if (state.character && updated.id == state.character.id) {
      state.character = { ...state.character, ...updated };
    }
    state.party = (state.party || []).map((p) =>
      p && p.id == updated.id ? { ...p, ...updated } : p
    );
  }

  async function setActiveCharacter(characterId) {
    try {
      await API.post('session/select', { character_id: characterId });
      await refreshAll();
      log(`${state.character.name} is now the active character.`, 'important');
    } catch (e) {
      showError(e.message);
    }
  }

  /** Pickable avatars, fetched once and kept for the session. */
  async function loadAvatars() {
    if (state.avatars) return state.avatars;
    try {
      const r = await API.get('meta/avatars');
      state.avatars = r.avatars || [];
    } catch (_) {
      // The sheet is still useful without a picker.
      state.avatars = [];
    }
    return state.avatars;
  }

  /**
   * Re-read the world and redraw.
   *
   * `location/state` carries the scene, the region chart, the character and
   * any combat already in progress, because they are always wanted together
   * and a screen assembled from four round trips flickers through three wrong
   * states on the way to the right one.
   */
  async function refreshAll() {
    let data;
    try {
      data = await API.get('location/state');
    } catch (e) {
      if (e && e.auth_required) {
        window.location.href = 'login.php';
        return;
      }
      showError(e.message);
      return;
    }

    state.character = data.character || state.character;
    state.location = data.location || null;
    state.regionMap = data.region_map || null;
    state.combat = data.combat || null;
    state.knownNpcIds = data.known_npc_ids || [];
    state.mode = state.combat && state.combat.is_active ? 'combat' : 'location';

    await loadParty();
    await loadQuests();
    await loadCompanions();
    render();

    if (window.LevelUp && typeof window.LevelUp.checkPending === 'function') {
      window.LevelUp.checkPending();
    }
  }

  async function loadParty() {
    try {
      const r = await API.get('session/status');
      state.partyId = r.party_id || null;
      if (r.party) state.party = r.party;
      else if (state.character) state.party = [state.character];
    } catch (_) {
      if (state.character) state.party = [state.character];
    }
  }

  /**
   * The companion roster: who is with you, who is at camp, and how they feel.
   *
   * ui-panels.js reads it for the camp screen and ui-dialog.js for the face and
   * name on a companion's interjection — which is why it is loaded here rather
   * than by whichever panel happens to open first.
   */
  async function loadCompanions() {
    if (!state.partyId) return;
    try {
      const r = await API.get('character/companions');
      state.companions = r.companions || [];
    } catch (_) {
      // Not fatal: an interjection falls back to the companion's key.
    }
  }

  async function loadQuests() {
    try {
      const r = await API.get('quest/list');
      state.quests = r.mine || [];
      state.questMarkers = r.markers || [];
    } catch (_) {
      // The tracker stays as it was; a failed quest fetch must not take the
      // scene down with it.
    }
  }

  // =========================================================================
  // Rendering
  // =========================================================================

  function render() {
    const locRoot = $('#location-root');
    const combatRoot = $('#combat-root');
    const inCombat = state.mode === 'combat';

    if (locRoot) locRoot.classList.toggle('hidden', inCombat);
    if (combatRoot) combatRoot.classList.toggle('hidden', !inCombat);

    // A fight draws its own portraits, its own health and its own action
    // economy. Leaving the exploration rails up beside it is two sets of the
    // same information with two chances to disagree, and it costs the board
    // the width it needs for four ranks. CSS stands them down off this class;
    // the log stays, because it is the one panel a fight makes MORE useful.
    document.body.classList.toggle('in-combat', inCombat);

    // Where you are, in the bar beside the menu.
    //
    // Set here rather than in renderLocation() because renderLocation() is the
    // branch a fight does NOT take, and a page reloaded mid-combat would then
    // draw an empty bar. It is only ever written, never cleared: the region is
    // true while the fight is on, and blanking it per mode would flicker.
    const where = $('#topbar-where');
    if (where && state.location && state.location.region_name) {
      const region = state.location.region_name;
      if (where.textContent !== region) where.textContent = region;
    }

    if (inCombat && window.Combat) {
      window.Combat.render();
    } else {
      renderLocation();
    }

    if (window.UI) {
      window.UI.renderPartyRail();
      window.UI.renderQuestTracker();
      window.UI.renderActionBar();
    }
  }

  /**
   * The scene.
   *
   * Reads top to bottom the way the player thinks about it: where am I, what
   * is it like, what can I do — and who is here lives on the left rail under
   * the party, so a crowded street never has to share the prose column.
   * Where can I go is the chart, which highlights the places you can walk to
   * from here.
   */
  function renderLocation() {
    const root = $('#location-root');
    if (!root) return;
    const loc = state.location;
    if (!loc) {
      root.innerHTML = '<p class="help-hint">Loading…</p>';
      return;
    }

    // Before the scene is built, not after: sceneMap() draws from the cursor.
    fpSync();

    // Heading and actions stay put; THE MIDDLE IS THE MAP.
    //
    // It used to be the establishing art and the prose, with the chart behind
    // a keystroke — which had the screen's largest space holding the thing you
    // read once and a modal holding the thing you consult constantly. A player
    // opened the map, looked, closed it, and did that again every time they
    // wanted to know where they could go. So they have swapped: the chart is
    // the scene, and the description is a click away under the ⓘ in its
    // corner. Nothing was lost — the prose modal carries the art with it, and
    // the first-visit paragraph still opens on arrival.
    root.innerHTML = `
      <div class="loc-inner">
        <div class="loc-text">
          <h1 class="loc-name">${esc(loc.name)}, ${esc(loc.region_name)}</h1>
          ${sceneMap()}
          <div class="loc-foot">
            ${pitPanel(loc)}
            ${waysPanel(loc)}
            ${actionsPanel(loc)}
          </div>
        </div>
      </div>`;

    mountSceneMap();
    renderPeopleRail();
  }

  /**
   * The chart, in the scene, with its controls on it.
   *
   * Same `.map-zoom` group the modal carried — same glyphs, same `data-zoom`
   * verbs — because a player who learned − ⌂ + on the old map has learned it
   * here. What is new is the ⓘ, in the corner opposite: the description of the
   * place you are standing in, which is what this space used to be.
   */
  function sceneMap() {
    if (!state.regionMap || !window.WorldMap) {
      return '<div class="loc-map is-empty"><p class="help-hint">Nowhere charted yet.</p></div>';
    }
    if (fpOn()) return firstPersonScene();
    return `<div class="loc-map" id="scene-map">
      ${window.WorldMap.svg(state.regionMap,
        { ways: currentWays(), quests: questPins(), party: partyMarks() })}
      <div class="map-zoom" role="group" aria-label="Zoom">
        <button type="button" class="icon-btn icon-btn-sm" data-zoom="out"
                title="Zoom out (−)" aria-label="Zoom out">−</button>
        <button type="button" class="icon-btn icon-btn-sm" data-zoom="reset"
                title="Fit the whole region (0)" aria-label="Fit the whole region">⌂</button>
        <button type="button" class="icon-btn icon-btn-sm" data-zoom="in"
                title="Zoom in (+) · drag to pan" aria-label="Zoom in">+</button>
      </div>
      ${fpToggle('Walk it in first person (V)', 'Look')}
      <button type="button" class="icon-btn map-info" id="scene-info"
              title="What this place looks like (I)" aria-label="Describe this place">i</button>
    </div>`;
  }

  // =========================================================================
  // The dungeon, from inside it
  //
  // A generated floor can be walked a step at a time instead of read off the
  // chart. Only a generated floor: the toggle is offered when the payload
  // carries a raster and not otherwise, so authored regions are untouched by
  // construction rather than by a check. The chart stays one keypress away and
  // stays the thing you travel from by clicking — this is a second way to look
  // at the same floor, not a replacement for reading it.
  // =========================================================================

  /** Remembered across sessions; the view is a preference, not a mode. */
  const FP_PREF = 'rpg.firstperson';

  function fpTiles() {
    const map = state.regionMap;
    return (map && map.floorplan && map.floorplan.tiles) || null;
  }

  /** Offered only where there is a raster to draw and a renderer to draw it. */
  const fpAvailable = () => !!(fpTiles() && window.FirstPerson);
  const fpOn = () => fpAvailable() && state.fp.on;

  function fpToggle(title, label) {
    if (!fpAvailable()) return '';
    return `<button type="button" class="icon-btn map-look" id="scene-look"
              title="${esc(title)}" aria-label="${esc(title)}">${esc(label)}</button>`;
  }

  /**
   * Put the camera where the server says the party is.
   *
   * THE ONE PLACE THE CURSOR IS RECONCILED, and it has to be one place because
   * of how many ways the two can part: arriving somewhere, a journey cut short
   * by a trap or a wandering monster, going down the stair, waking up in the
   * haven after a defeat, and a plain reload with no cursor at all. Each of
   * those ends in a render, so all of them are covered by asking here whether
   * the tile under the camera still belongs to the location the party is in,
   * and standing them in the middle of it when it does not.
   */
  function fpSync() {
    const tiles = fpTiles();
    const here = state.location ? +state.location.id : null;
    if (!tiles || !window.FirstPerson || here === null) {
      state.fp.cursor = null;
      return;
    }
    if (window.FirstPerson.agrees(tiles, state.fp.cursor, here)) return;
    const facing = state.fp.cursor ? state.fp.cursor.facing : 0;
    state.fp.cursor = window.FirstPerson.place(tiles, here, facing);
  }

  function firstPersonScene() {
    const tiles = fpTiles();
    const cursor = state.fp.cursor;
    if (!cursor) {
      return '<div class="loc-map is-empty"><p class="help-hint">Nowhere charted yet.</p></div>';
    }
    const way = window.FirstPerson.facings[cursor.facing];
    return `<div class="loc-map is-fp" id="scene-map">
      <div class="fp-stage" id="scene-fp">${window.FirstPerson.svg(tiles, cursor,
        { label: `Looking ${way} from ${state.location ? state.location.name : 'here'}` })}</div>
      <p class="fp-compass" id="scene-compass" aria-live="polite">Facing ${esc(way)}</p>
      <div class="fp-walk" role="group" aria-label="Walk">
        <button type="button" class="icon-btn" data-fp="left"
                title="Turn left (←)" aria-label="Turn left">↰</button>
        <button type="button" class="icon-btn fp-fwd" data-fp="forward"
                title="Step forward (↑)" aria-label="Step forward">↑</button>
        <button type="button" class="icon-btn" data-fp="about"
                title="Turn about (↓)" aria-label="Turn about">↻</button>
        <button type="button" class="icon-btn" data-fp="right"
                title="Turn right (→)" aria-label="Turn right">↱</button>
      </div>
      ${fpToggle('Read the floor plan (V)', 'Map')}
      <button type="button" class="icon-btn map-info" id="scene-info"
              title="What this place looks like (I)" aria-label="Describe this place">i</button>
    </div>`;
  }

  /** Redraw the picture alone, so turning on the spot costs no request. */
  function fpRedraw() {
    const stage = document.getElementById('scene-fp');
    const tiles = fpTiles();
    if (!stage || !tiles || !state.fp.cursor) {
      render();
      return;
    }
    const way = window.FirstPerson.facings[state.fp.cursor.facing];
    stage.innerHTML = window.FirstPerson.svg(tiles, state.fp.cursor, { label: `Looking ${way}` });
    const compass = document.getElementById('scene-compass');
    if (compass) compass.textContent = `Facing ${way}`;
  }

  function fpTurn(by) {
    if (!state.fp.cursor) return;
    state.fp.cursor = window.FirstPerson.turn(state.fp.cursor, by);
    fpRedraw();
  }

  /**
   * One step forward, which is one of three things.
   *
   * Inside the place you are already standing it is a redraw and no request at
   * all. Across a threshold it is the ordinary one-hop `location/travel` the
   * chart has always issued, so the trap, the wandering monster, the locked
   * door and the refusal all behave exactly as they do when you click the map.
   * Into a wall it is nothing, and says so.
   */
  async function fpForward() {
    const tiles = fpTiles();
    if (!tiles || !state.fp.cursor || state.busy) return;
    const here = state.location ? +state.location.id : null;
    const move = window.FirstPerson.step(tiles, state.fp.cursor, here);

    if (move.blocked) {
      const stage = document.getElementById('scene-fp');
      if (stage && !reducedMotion()) {
        stage.classList.remove('is-bump');
        void stage.offsetWidth;      // restart the animation
        stage.classList.add('is-bump');
      }
      return;
    }
    if (!move.travel) {
      state.fp.cursor = move.cursor;
      fpRedraw();
      return;
    }
    // Step onto the threshold before asking, so the picture moves when the key
    // does. fpSync() is what decides where the party really ended up.
    state.fp.cursor = move.cursor;
    await travelTo(move.travel);
  }

  function fpFlip() {
    state.fp.on = !state.fp.on;
    try { window.localStorage.setItem(FP_PREF, state.fp.on ? '1' : '0'); } catch (e) { /* private mode */ }
    render();
  }

  /**
   * Optional establishing painting for this place.
   *
   * Authored art lives at assets/images/locations/<location_key>.png. Nothing
   * checks whether the file exists: the <img> removes itself on error so a
   * location without art just shows prose, the same way a companion without
   * camp art falls back to a portrait. Lives inside the scroll so it can be
   * scrolled away; it must never pin the description off-screen.
   */
  function locationArt(loc) {
    const key = loc && loc.key;
    if (!key) return '';
    const src = `assets/images/locations/${encodeURIComponent(key)}.png`;
    return `<div class="loc-art">
      <img src="${esc(src)}" alt=""
           onerror="this.closest('.loc-art').remove()">
    </div>`;
  }

  /**
   * The view the scene chart is looking through, kept across redraws.
   *
   * `render()` rebuilds the whole scene for anything that changes — a hit
   * point, a quest, a search — and each rebuild is a new SVG. Without this the
   * chart snapped back to its opening zoom every time, so leaning out to look
   * at the far side of a region survived exactly until you pressed a button.
   *
   * Dropped when the party MOVES, which is the one case where snapping back is
   * right: arriving somewhere means re-centring on where you now are.
   */
  let sceneView = null;
  let sceneViewAt = null;

  function mountSceneMap() {
    const box = $('#scene-map');
    if (!box || !window.WorldMap) return;

    const look = $('#scene-look');
    if (look) look.addEventListener('click', fpFlip);

    // The first-person view has no chart under it, so none of the pan, zoom or
    // quest-pin wiring below applies to it.
    if (fpOn()) {
      const info = $('#scene-info');
      if (info) info.addEventListener('click', openDescription);
      box.querySelectorAll('[data-fp]').forEach((b) => {
        b.addEventListener('click', () => {
          const verb = b.dataset.fp;
          if (verb === 'forward') fpForward();
          else if (verb === 'left') fpTurn(-1);
          else if (verb === 'right') fpTurn(1);
          else if (verb === 'about') fpTurn(2);
        });
      });
      return;
    }

    const here = state.location ? +state.location.id : null;
    if (sceneViewAt !== here) {
      sceneView = null;
      sceneViewAt = here;
    }
    // Opens near enough to read the street you are on, far enough to see what
    // is round it. The modal's 2.5 was chosen for a full window and is a street
    // with nothing beside it in a panel a third that size; 1.3 was the other
    // way, a whole region seen from too far to read the place you are standing
    // in. The chart is the scene now — it should open where you are.
    const ctl = window.WorldMap.controls(box, sceneView, 1.7);
    const info = $('#scene-info');
    if (info) info.addEventListener('click', openDescription);
    if (!ctl) return;

    box.querySelectorAll('[data-zoom]').forEach((b) => {
      b.addEventListener('click', () => {
        if (b.dataset.zoom === 'in') ctl.zoomIn();
        else if (b.dataset.zoom === 'out') ctl.zoomOut();
        else ctl.reset();
        sceneView = ctl.state();
      });
    });
    box.addEventListener('pointerup', () => { sceneView = ctl.state(); });
    box.addEventListener('wheel', () => { sceneView = ctl.state(); }, { passive: true });

    bindQuestTips(box);
  }

  /**
   * What this place looks like, on demand.
   *
   * The establishing art and the prose, in the modal stack rather than in the
   * scene — see renderLocation() for the trade. The first-visit paragraph is
   * inside prosePanel() and still leads, so a room read for the first time
   * reads the same way it always did; what changed is that it is opened rather
   * than always open.
   */
  function openDescription() {
    const loc = state.location;
    if (!loc || !window.UI) return;
    window.UI.showModal(`
      <div class="modal-head">
        <h2>${esc(loc.name)}</h2>
        <button type="button" class="icon-btn modal-x" data-modal-close
                title="Close (Esc)" aria-label="Close">×</button>
      </div>
      <div class="loc-desc-body">
        ${locationArt(loc)}
        ${prosePanel(loc)}
      </div>`, { size: 'wide', slot: 'description', label: loc.name });
  }

  function prosePanel(loc) {
    // The first-visit paragraph leads, once, and is set apart: it is the
    // establishing shot, and reading it again on every return would wear it
    // out.
    const first = loc.first_visit && loc.first_visit_text
      ? `<p class="loc-first">${esc(loc.first_visit_text)}</p>` : '';
    const amb = pickAmbience(loc);
    return `<div class="loc-prose">
      ${first}
      <p>${esc(loc.description)}</p>
      ${amb ? `<p class="loc-ambience">${esc(amb)}</p>` : ''}
    </div>`;
  }

  /**
   * One rotating line of flavour.
   *
   * Chosen per render rather than per visit, so a scene looked at twice is not
   * word for word identical. Deliberately not persisted: it is weather, and
   * remembering the weather would make it a fact.
   */
  function pickAmbience(loc) {
    const lines = loc.ambience || [];
    if (!lines.length) return null;
    return lines[Math.floor(Math.random() * lines.length)];
  }

  /**
   * Who is here, drawn into the left rail beside the party.
   *
   * The authored cast leads; ambient townsfolk follow in a muted second list,
   * because a market square holds a dozen of them and they must not compete
   * with the innkeeper for the eye.
   *
   * Somebody the party has not met shows their role rather than their name —
   * "Innkeeper", not "Mara Hearthstone" — which is why talking to people is
   * how you learn who they are. The crowd is exempt: an ambient townsperson is
   * authored as "A baker", and that IS their name.
   */
  function renderPeopleRail() {
    const rail = $('#people-rail');
    if (!rail) return;
    const npcs = (state.location && state.location.npcs) || [];
    const count = $('#people-count');
    if (count) {
      count.textContent = String(npcs.length);
      count.classList.toggle('hidden', !npcs.length);
    }
    if (!npcs.length) {
      rail.innerHTML = '<p class="help-hint">Nobody here but you.</p>';
      return;
    }
    const named = npcs.filter((n) => !n.is_ambient);
    const crowd = npcs.filter((n) => n.is_ambient);
    rail.innerHTML = `
      ${named.length ? `<ul class="people-list">${named.map(personRow).join('')}</ul>` : ''}
      ${crowd.length ? `<div class="people-crowd">
        <h3>Also about</h3>
        <ul class="people-list is-crowd">${crowd.map(personRow).join('')}</ul>
      </div>` : ''}`;
  }

  function personRow(n) {
    const face = variantUrl(n.image_url, '_face')
      || spriteArtUrl(n.sprite_key || DEFAULT_SPRITE_KEY, '_face');
    const name = (n.known || n.is_ambient) ? n.name : (n.role || 'A stranger');
    // Tags earn their place by changing what the player would do next: a coin
    // means there is stock to buy, a mark means there is work to take.
    // `has_work` rather than `is_quest_giver`: the first is about this party,
    // the second is a permanent fact about the character, and a mark that never
    // goes out stops meaning anything.
    const tags = [
      n.is_merchant ? '<span class="person-tag is-shop" title="Sells goods">◈</span>' : '',
      n.has_work ? '<span class="person-tag is-quest" title="Has work">!</span>' : '',
    ].join('');
    return `<li>
      <button type="button" class="person" data-npc="${n.id}"
              title="${esc(n.description || '')}">
        <img class="person-face" src="${esc(face)}" alt=""
             onerror="this.classList.add('is-missing')">
        <span class="person-text">
          <span class="person-name">${esc(name)}${tags}</span>
        </span>
      </button>
    </li>`;
  }

  /**
   * What can be done here.
   *
   * Only what is actually possible: no greyed-out Rest at a location with no
   * innkeeper, because a menu of things you cannot do is a menu you stop
   * reading. Where you can GO is on the chart, not here.
   */
  /**
   * The pit's card for tonight.
   *
   * Its own panel rather than three more rows on the action bar: the tiers are
   * a choice between each other, and what makes the choice is the line saying
   * roughly what comes through the gate. The sample is rolled per look, so it
   * is a fair idea of the match rather than a promise about it — which is why
   * it says "or so" and why the bout is built server-side when you commit.
   */
  /**
   * The pit's card: three bouts and a readout, two by two.
   *
   * The three tiers were a full-width column of two-line buttons, which is
   * three rows of a scene that is mostly chart. There are exactly three of
   * them — PitEngine::TIERS — so a 2x2 has one cell spare, and what goes in it
   * is the question the buttons cannot answer: how many more of these until
   * somebody levels.
   *
   * The counts are the ENGINE's. PitEngine::toLevel divides the remaining XP
   * by what a win at each tier pays one character, which is CombatEngine's own
   * split and not a rule restated here — the same reason the combat board does
   * no geometry of its own.
   */
  function pitPanel(loc) {
    const tiers = loc.pit && loc.pit.tiers;
    if (!tiers || !tiers.length) return '';

    // A tier's short name, for the readout: the labels read "A fair match" and
    // a column of them would be three sentences where three nouns will do.
    const shortName = (label) => label.replace(/^(a|an)\s+/i, '');

    const toLevel = loc.pit.to_level;
    const readout = toLevel
      ? `<div class="pit-level" title="${esc(toLevel.who)} is ${
            toLevel.remaining.toLocaleString()} XP short">
           <span class="pit-level-head">To level ${toLevel.level}</span>
           <ul class="pit-level-counts">
             ${tiers.map((t) => {
               const n = toLevel.bouts && toLevel.bouts[t.tier];
               return n ? `<li>${esc(shortName(t.label))} <b>&times;${n}</b></li>` : '';
             }).join('')}
           </ul>
         </div>`
      : '';

    return `<section class="loc-pit">
      <h2>Take a bout</h2>
      <div class="pit-grid">
        ${tiers.map((t) => `
          <button type="button" class="btn loc-act pit-bout" data-act="pit" data-id="${esc(t.tier)}"
                  title="${esc(t.blurb)}">
            <span class="pit-label">${esc(t.label)}</span>
            <span class="pit-sample">${esc(t.sample)}, or so</span>
          </button>`).join('')}
        ${readout}
      </div>
    </section>`;
  }

  function actionsPanel(loc) {
    const acts = [];
    if (loc.encounter) {
      // The roster leads the hint. A party that walked away from this fight and
      // came back is making the same decision the arrival dialog offered them,
      // and it should not need re-opening to see what it is about.
      const seen = loc.encounter.foes_text;
      acts.push({
        key: 'F', label: `Fight: ${loc.encounter.name}`,
        hint: [seen, loc.encounter.description].filter(Boolean).join(' — '),
        act: 'fight', id: loc.encounter.id, danger: true,
      });
    }
    // A locked way the party may put a shoulder to. Only the generated kind
    // carries `attempt` — an authored locked way opens with story, not
    // Strength — and the button opens the same two-phase die ceremony a
    // dialogue check does: see the DC, weigh the boosts, then commit.
    (loc.exits || []).forEach((e) => {
      if (e.locked && e.attempt) {
        acts.push({
          label: `${e.attempt === 'lift' ? 'Lift' : 'Force'}: ${e.label}`,
          hint: e.attempt === 'lift'
            ? 'Bare strength, and the noise it makes'
            : 'Shoulder, boot or bar — and the noise it makes',
          act: 'force', id: e.id,
        });
      }
    });
    acts.push({ key: 'S', label: 'Search', hint: 'Look the place over', act: 'search' });
    if (loc.job_board) {
      acts.push({
        key: 'B', label: 'Read the job board',
        hint: 'Work posted publicly. Reading it is what puts it in your journal',
        act: 'board',
      });
    }
    (loc.items || []).forEach((it) => {
      acts.push({
        key: 'L', label: `Take ${it.name}`, hint: it.description || '',
        act: 'loot', id: it.location_item_id,
      });
    });
    if (loc.inn_cost !== null && loc.inn_cost !== undefined) {
      acts.push({
        key: 'R', label: `Rest at the inn (${loc.inn_cost} gp)`,
        hint: 'A bed, a long rest, and spells back', act: 'rest',
      });
    }
    if (loc.allow_camp) {
      acts.push({
        key: 'R', label: 'Make camp',
        hint: 'Sit with the party, and take the long rest when you are ready',
        act: 'camp',
      });
    }
    // The stair. `delve` is null everywhere but the Undervault, so no other
    // module grows a button about a hole it has not got. Which of the three
    // shows is the server's call, not a guess from the location key.
    if (loc.delve && loc.delve.can_descend) {
        acts.push({
          key: 'D', label: 'Go down the stair',
          hint: 'Into the Undervault. It is not the same twice',
          act: 'descend', danger: true,
        });
    }
    if (loc.delve && loc.delve.can_deeper) {
        acts.push({
          key: 'D', label: `Take the stair down (level ${loc.delve.depth + 1})`,
          hint: 'Deeper. What is down there is worse, and worth more',
          act: 'descend', danger: true,
        });
    }
    if (loc.delve && loc.delve.can_leave) {
        acts.push({
          key: 'U', label: 'Climb out',
          hint: 'Back up to the Mouth. This floor closes behind you',
          act: 'leave_dungeon',
        });
    }
    // Offered wherever a long rest is, because whatever makes a place safe
    // enough to sleep makes it safe enough to sit down for an hour.
    if (loc.allow_camp || loc.inn_cost !== null) {
      acts.push({
        key: 'H', label: 'Take an hour',
        hint: 'A short rest: one hit die each, and Second Wind back',
        act: 'short_rest',
      });
    }
    return `<section class="loc-actions">
      <h2>Actions</h2>
      <div class="action-list">
        ${acts.map(actionKey).join('')}
      </div>
    </section>`;
  }

  /**
   * Which glyph an action wears.
   *
   * The stair takes one glyph and flips it, which is the whole of the argument
   * for a chevron over anything more pictorial: down and up are the same
   * decision made in opposite directions, and a player who has learned one has
   * learned the other.
   */
  const ACTION_ICONS = {
    fight: 'i-attack',
    force: 'i-shove',
    search: 'i-sense',
    board: 'i-journal',
    loot: 'i-hands',
    rest: 'i-heart',
    camp: 'i-camp',
    short_rest: 'i-hourglass',
    descend: 'i-chevron',
    leave_dungeon: 'i-chevron',
    pit: 'i-attack',
  };

  /**
   * One action, as a key on a bar.
   *
   * IT WAS A COLUMN OF FULL-WIDTH BUTTONS, one line each, and a scene with a
   * fight in it, a job board, two things on the floor, a bed and a fire ran to
   * eight rows — a screen of furniture under the prose the screen is for. The
   * verbs are a bar now, the same icon keys combat uses, so the common scene is
   * one row.
   *
   * WHAT KEEPS ITS WORDS IS WHAT NAMES SOMETHING. "Take Rusted Key" and
   * "Fight: Cellar Rats" are not verbs with a label attached, they are the only
   * place the game says there is a key on the floor or what is waiting in the
   * room; an icon and a tooltip would hide the content behind a hover. So an
   * action carrying an id — loot, a fight, a forced door, a bout — keeps its
   * text beside the glyph, and the fixed verbs do not.
   *
   * The key hint stays drawn, in the corner. A menu-driven game where nobody
   * discovers the letter keys is a page of buttons, which is the note the old
   * `<kbd>` carried and it survives the change.
   */
  function actionKey(a) {
    const icon = ACTION_ICONS[a.act] || 'i-hands';
    const named = !!a.id;
    const cls = [
      'act-slot', 'loc-act',
      named ? 'has-label' : '',
      a.danger ? 'is-danger' : '',
      a.act === 'leave_dungeon' ? 'is-up' : '',
    ].filter(Boolean).join(' ');
    // The tooltip says the label as well as the hint on a key that draws no
    // label — otherwise "Read the job board" exists nowhere but the icon.
    const tip = [named ? '' : a.label, a.hint].filter(Boolean).join(' — ');
    return `<button type="button" class="${cls}"
        data-act="${esc(a.act)}"${a.id ? ` data-id="${a.id}"` : ''}
        title="${esc(tip)}" aria-label="${esc(a.label)}">
      <svg class="act-icon" aria-hidden="true"><use href="#${icon}"></use></svg>
      ${named ? `<span class="loc-act-label">${esc(a.label)}</span>` : ''}
      ${a.key ? `<kbd>${esc(a.key)}</kbd>` : ''}
    </button>`;
  }

  /**
   * Direct exits from the current scene, shaped for the chart.
   *
   * The map draws these as highlighted place-names (with their number keys
   * in the text). The scene does not list them again — the chart already is
   * that list, except for the ways the chart cannot draw; see waysPanel.
   */
  function currentWays() {
    const loc = state.location;
    if (!loc) return [];
    return (loc.exits || []).map((e) => ({
      id: e.to_location_id,
      locked: !!e.locked,
      label: e.label || e.to_name || '',
    }));
  }

  /**
   * Every destination the chart can actually be clicked through to.
   *
   * Two kinds: a node in this region, and the stub arrow for a place in a
   * neighbouring one. The stub is only drawn when the location it leaves from
   * is itself on the chart, which is the same test ui-map.js makes.
   *
   * The chart also withholds unvisited places it cannot name — an in-region
   * node it does not draw at all, a border stub it draws without a click. Both
   * of those are safe to count as charted here, because the exemption is for
   * places that are NOT a step from this scene, and this set exists only to
   * decide whether a step from this scene has somewhere to be clicked. A
   * direct exit is always drawn and always clickable; see ui-map.js `node`.
   */
  function chartedDestinations() {
    const map = state.regionMap;
    if (!map || !map.nodes || !map.nodes.length) return new Set();
    const nodeIds = new Set(map.nodes.map((n) => +n.id));
    const reachable = new Set(nodeIds);
    (map.neighbors || []).forEach((nb) => {
      if (nodeIds.has(+nb.from)) reachable.add(+nb.to_location_id);
    });
    return reachable;
  }

  /**
   * The ways out the chart cannot show.
   *
   * A place marked hidden_until_visited is left off the region map until the
   * party has been there, and the edge to it goes with it. When the exit
   * itself is open that leaves a way out that exists in the payload, is legal
   * to walk, and appears nowhere a player can click — you would have to
   * already have been through the door to be shown the door. The Flagon
   * cellar was exactly this.
   *
   * So: anything unreachable on the drawing gets listed here instead, keeping
   * the promise that an unlocked exit is always takeable. Usually this renders
   * nothing at all, which is the intended state — the chart is still the way
   * you travel.
   */
  function waysPanel(loc) {
    const charted = chartedDestinations();
    const stranded = (loc.exits || []).filter((e) => !charted.has(+e.to_location_id));
    if (!stranded.length) return '';
    return `<section class="loc-ways">
      <h2>Ways out</h2>
      <div class="action-list">
        ${stranded.map((exit) => `
          <button type="button" class="btn loc-act" data-act="travel"
                  data-id="${exit.to_location_id}"${exit.locked ? ' disabled' : ''}
                  title="${esc(exit.locked ? 'Shut to you for now' : (exit.to_name || ''))}">
            <span>${esc(exit.label || exit.to_name || '')}</span>
          </button>`).join('')}
      </div>
    </section>`;
  }

  /**
   * Where the open quests want the party, for the chart to mark.
   *
   * Straight off the markers the server already routes — one per active quest
   * with a target — so the ? on the map and the directions in the tracker are
   * the same fact told twice rather than two guesses. A marker pointing into
   * another region simply matches no node here and is quietly dropped; the
   * chart draws one region at a time.
   */
  function questPins() {
    return (state.questMarkers || []).map((m) => ({
      id: m.location_id,
      tracked: !!m.tracked,
      title: m.title,
      objective: m.objective,
    }));
  }

  /**
   * Who the chart draws standing in this scene.
   *
   * The roster the party rail draws, in the order the rail draws it, with the
   * same face art — `classArtUrl`, not the character's `image_url` — so the
   * third face along the rail is the third face on the map. Two portraits of
   * one character that disagree is the fault the whole `_face`/`_bust` cutting
   * rule exists to avoid, and it would be just as wrong across two panels.
   *
   * There is one party and it is in one place: the chart puts all of these on
   * whichever node is `current`, and never has to ask where anyone is.
   */
  function partyMarks() {
    const members = (state.party && state.party.length ? state.party : [state.character])
      .filter(Boolean);
    return members.map((pc) => ({
      name: pc.name,
      face: classArtUrl(pc, '_face'),
      hp: pc.current_hp,
      max_hp: pc.max_hp,
      level: pc.level,
      active: !!(state.character && pc.id == state.character.id),
    }));
  }

  /**
   * The quest pins, explaining themselves on hover.
   *
   * One scripted tooltip, for the combat bar's reasons: the browser's own
   * cannot be styled and arrives a second and a half late. Fixed-positioned at
   * the pin so pan and zoom cannot strand it, and hidden the moment either
   * starts.
   *
   * Lived inside the map modal until the chart moved into the scene. It takes
   * the container it is given and reaches for nothing outside it, which is the
   * whole of what that move cost.
   */
  function bindQuestTips(box) {
    const tip = document.createElement('div');
    tip.className = 'wm-tip';
    tip.setAttribute('role', 'tooltip');
    tip.hidden = true;
    document.body.appendChild(tip);

    box.addEventListener('mouseover', (ev) => {
      const pin = ev.target.closest('[data-quest-pin]');
      if (!pin) return;
      tip.innerHTML = (pin.dataset.questInfo || '').split('\n').filter(Boolean)
        .map((line) => {
          const [quest, objective] = line.split('\t');
          return `<span class="wm-tip-name">${esc(quest || '')}</span>`
            + (objective ? `<span class="wm-tip-why">${esc(objective)}</span>` : '');
        }).join('');
      tip.hidden = false;
      const at = pin.getBoundingClientRect();
      const below = at.top < 76;                    // no room above — flip under
      tip.classList.toggle('is-below', below);
      tip.style.top = `${Math.round(below ? at.bottom + 6 : at.top - 6)}px`;
      // Centred on the pin, then clamped inside the viewport once measured.
      const w = tip.offsetWidth;
      const cx = Math.max(8 + w / 2,
        Math.min(at.left + at.width / 2, window.innerWidth - 8 - w / 2));
      tip.style.left = `${Math.round(cx)}px`;
    });
    box.addEventListener('mouseout', (ev) => {
      if (ev.target.closest('[data-quest-pin]')) tip.hidden = true;
    });
    ['pointerdown', 'wheel'].forEach((evName) => {
      box.addEventListener(evName, () => { tip.hidden = true; }, { passive: true });
    });
    // The scene is rebuilt on every render, so the tip it hung on the body has
    // to go with the box it belonged to.
    const drop = new MutationObserver(() => {
      if (!box.isConnected) {
        tip.remove();
        drop.disconnect();
      }
    });
    drop.observe(document.body, { childList: true, subtree: true });
  }

  /**
   * Walk the party across the chart in the scene.
   *
   * It used to walk across the map MODAL, and only when that was what you set
   * off from — travel by any other route had no chart on screen and pausing
   * would have been a second of nothing happening. The chart is the scene now,
   * so there is always one to walk across and every trip is animated.
   *
   * The route is the server's own `steps`, resolved to positions against the
   * chart already drawn. A hop that has no node here is where the walk ends:
   * that is a step out of the region, and the chart draws one region at a
   * time — there is nowhere on this drawing for the party to be walked to.
   */
  async function walkMap(steps) {
    const el = $('#scene-map');
    const map = state.regionMap;
    if (!el || !map || !window.WorldMap || !window.WorldMap.walk) return;

    const here = (map.nodes || []).find((n) => n.current);
    if (!here) return;

    const points = [{ x: here.x, y: here.y }];
    for (const step of steps || []) {
      const n = (map.nodes || []).find((m) => +m.id === +step.to_id);
      if (!n) break;
      points.push({ x: n.x, y: n.y });
    }
    await window.WorldMap.walk(el, points, { instant: reducedMotion() });
    // A beat standing at the destination before the scene is redrawn under
    // them. Without it the render lands on the same frame the party arrives,
    // and the last thing you see is them still moving.
    if (!reducedMotion()) await sleep(ARRIVAL_HOLD);
  }

  // =========================================================================
  // Doing things
  // =========================================================================

  /**
   * One request at a time, and the menus go inert while it is in flight.
   *
   * Every action in the scene is a POST that changes the world, and two of
   * them overlapping is how a party searches a room they have already left.
   * `state.busy` is the flag the renderers read; `is-busy` on the body is what
   * the cursor and the disabled styling hang off.
   */
  async function guarded(fn) {
    if (state.busy) return;
    state.busy = true;
    document.body.classList.add('is-busy');
    try {
      await fn();
    } catch (e) {
      showError(e.message);
    } finally {
      state.busy = false;
      document.body.classList.remove('is-busy');
    }
  }

  /**
   * One click listener for the whole page, bound once at boot.
   *
   * Delegation rather than per-button handlers because renderLocation()
   * replaces its entire subtree on every change, and re-binding dozens of
   * buttons after every render is how a menu ends up with a dead button in it.
   */
  function bindDelegates() {
    document.body.addEventListener('click', (ev) => {
      // The chart, anywhere on the page: a place-name travels, wherever the
      // chart happens to be drawn.
      const node = ev.target.closest('[data-map-node]');
      if (node) { travelTo(+node.dataset.mapNode); return; }

      const person = ev.target.closest('[data-npc]');
      if (person) { talkTo(+person.dataset.npc); return; }

      if (!ev.target.closest('#location-root')) return;

      const act = ev.target.closest('[data-act]');
      if (act) { runAction(act.dataset.act, act.dataset.id); }
    });

    // The chart's nodes are SVG groups, so they are focusable by tabindex but
    // do not fire a click on Enter the way a <button> would.
    document.body.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Enter' && ev.key !== ' ') return;
      const node = ev.target.closest && ev.target.closest('[data-map-node]');
      if (!node) return;
      ev.preventDefault();
      travelTo(+node.dataset.mapNode);
    });
  }

  /** What a `data-act` button does. The one place the verbs are resolved. */
  async function runAction(act, id) {
    switch (act) {
      case 'search': return doSearch();
      case 'loot': return doLoot(+id);
      case 'rest': return doRest();
      case 'camp': return openCampScreen();
      case 'short_rest': return doShortRest();
      case 'descend': return doDescend();
      case 'leave_dungeon': return doLeaveDungeon();
      case 'fight': return startCombat({ encounter_id: +id });
      case 'pit': return takeBout(String(id));
      case 'board': return readBoard();
      case 'force': return doForce(+id);
      case 'travel': return travelTo(+id);
      default: return undefined;
    }
  }

  function travelTo(locationId) {
    return guarded(async () => {
      // A refused trip — no way through, or a route that has since closed —
      // is reported in the error banner, which sits in the page's own flow
      // under the modal backdrop. Leaving the chart up would put the answer
      // where it cannot be read.
      // A refused trip is reported in the error banner, which sits in the
      // page's own flow — there is no chart over it to put the answer behind
      // any more.
      const r = await API.post('location/travel', { location_id: locationId });

      const steps = r.steps || [];

      // The walk is after the server has answered, so what it animates is a
      // journey that has already happened — including one cut short, which
      // arrives here as a shorter list of steps.
      await walkMap(steps);
      for (const step of steps) {
        log(`${step.label} — ${step.to_name}`, 'travel', 'story');
        if (!reducedMotion() && steps.length > 1) await sleep(STEP_PACE);
      }
      (r.messages || []).forEach((m) => log(m, 'important'));
      if (r.interrupted) log('Something stops you on the way.', 'danger', 'combat');

      await mapFadeThrough(() => refreshAll());
      announceArrival();
      await handleEvents(r.events || []);
    });
  }

  /** The arrival line: the place, and its opening sentence. */
  function announceArrival() {
    const loc = state.location;
    if (!loc) return;
    log(`— ${loc.name} —`, 'important');
    if (loc.first_visit && loc.first_visit_text) {
      log(loc.first_visit_text);
      return;
    }
    const opener = String(loc.description || '').split(/(?<=[.!?])\s/)[0];
    if (opener) log(opener);
  }

  /**
   * What is waiting, drawn as the faces the fight itself will use.
   *
   * The count is the server's, from CombatEngine::roster — the roster is scaled
   * to the party, so anything counted here would have been a guess that the
   * next screen contradicts.
   */
  function foeLineup(foes) {
    if (!foes || !foes.length) return '';
    const cards = foes.map((f) => {
      const key = f.sprite_key
        || (/([^/]+)\.(png|svg)/i.exec(String(f.image_url || '')) || [])[1];
      const face = key
        ? `<img class="foe-face" src="assets/images/monsters/${esc(key)}_face.png"
             alt="" onerror="this.remove()">`
        : '';
      return `<li class="foe-card">${face}
        <span class="foe-name">${esc(f.name)}</span>
        ${f.quantity > 1 ? `<span class="foe-count">×${f.quantity}</span>` : ''}
      </li>`;
    }).join('');
    return `<ul class="foe-lineup">${cards}</ul>`;
  }

  /**
   * What the world does about the party arriving.
   *
   * A random encounter is not negotiable — it has already happened, and a
   * dialog asking whether you would like to be ambushed reads as a bug. An
   * authored ambush is announced and confirmed, because it is a decision
   * point: the party can look at it and walk back out. Which is the whole
   * reason it now says WHO is over there — "Hold back" is not a choice anybody
   * can make against a name alone.
   */
  async function handleEvents(events) {
    for (const ev of events || []) {
      // A trap has already happened by the time it is reported — the save was
      // rolled and the damage taken server-side, mid-hop. This is the telling,
      // not the asking.
      if (ev.type === 'trap') {
        log(`${ev.name}!`, 'danger', 'combat');
        if (ev.text) log(ev.text, 'danger', 'story');
        const s = ev.save || {};
        const verdict = s.success ? 'holds' : 'fails';
        log(`${ev.victim} — ${String(s.ability || '').toUpperCase()} save ${s.total} against DC ${s.dc} ${verdict}`
          + (ev.damage > 0 ? ` — ${ev.damage} damage` : ' — unhurt'),
          s.success ? 'info' : 'danger', 'combat');
        continue;
      }
      if (ev.type !== 'encounter') continue;
      if (ev.random) {
        log(`${ev.name}!`, 'danger', 'combat');
        await startCombat({ encounter_id: ev.encounter_id });
        return;
      }
      const enc = state.location && state.location.encounter;
      const go = window.UI && window.UI.confirm
        ? await window.UI.confirm({
            title: ev.name,
            body: (enc && enc.description) || 'They have seen you.',
            bodyHtml: foeLineup(enc && enc.foes),
            confirm: 'Fight',
            cancel: 'Hold back',
          })
        : true;
      if (go) await startCombat({ encounter_id: ev.encounter_id });
      return;
    }
  }

  function doSearch() {
    return guarded(async () => {
      const r = await API.post('location/search', {});
      (r.messages || []).forEach((m) => log(m));
      // A found exit changes the scene, so redraw rather than leaving the
      // player to work out that the menu is now stale.
      if (r.found_exits) await refreshAll();
    });
  }

  /**
   * Put a shoulder to a locked door, through the same die ceremony as a
   * dialogue check: `location/force_door` offers the check, the ceremony
   * spends the boosts, and the resolve round-trip opens the door — or makes
   * the noise. Walking away from the die leaves the door exactly as it was;
   * the check is never claimed.
   */
  function doForce(exitId) {
    return guarded(async () => {
      const r = await API.post('location/force_door', { exit_id: exitId });
      let after = null;
      const rolled = await window.Check.open(r.check, {
        resolve: async (checkId, boosts) => {
          after = await API.post('location/force_door_resolve', { check_id: checkId, boosts });
          return after.result;
        },
      });
      if (!rolled || !after) return;
      (after.messages || []).forEach((m) => log(m, after.opened ? 'success' : 'danger'));
      // Opened: the exit's condition now passes, so the scene and the chart
      // both need re-reading either way — the map redraws the way unlocked.
      await refreshAll();
      await handleEvents(after.events || []);
    });
  }

  function doLoot(locationItemId) {
    return guarded(async () => {
      const r = await API.post('location/loot', { location_item_id: locationItemId });
      (r.messages || []).forEach((m) => log(m, 'success'));
      if (r.character) mergeCharacter(r.character);
      await refreshAll();
    });
  }

  function doRest() {
    return guarded(async () => {
      const r = await API.post('location/rest', {});
      if (r.message) log(r.message, 'success');
      (r.messages || []).forEach((m) => log(m, 'important'));
      await refreshAll();
    });
  }

  /**
   * Make camp: the screen, not the rest.
   *
   * "A long rest, and time to talk" is what this action has always promised,
   * and it used to post `location/camp` on the spot — so the night was over
   * before anybody had said anything, and the only way to the fire was the
   * tent icon in the HUD, which nothing pointed at. The screen is where both
   * halves live: sit with somebody, and Make camp there when you are done.
   */
  function openCampScreen() {
    if (window.UI && window.UI.openCamp) window.UI.openCamp();
  }

  /**
   * The long rest itself. Still exported, because the camp screen's own button
   * and anything else that wants to end the night outright goes through here.
   */
  function doCamp() {
    return guarded(async () => {
      const r = await API.post('location/camp', {});
      if (r.message) log(r.message, 'success');
      (r.messages || []).forEach((m) => log(m, 'important'));
      await refreshAll();
    });
  }

  /**
   * An hour rather than a night.
   *
   * A hit die each and the features whose SRD text says short rest — which
   * until now was a note in CombatEngine conceding there was no such rest to
   * give them back on. Repeatable: one die per character per rest is how "spend
   * three" is said, and it never wastes one on somebody already whole.
   */
  /**
   * Down the stair, into a floor that did not exist a moment ago.
   *
   * `refreshAll()` afterwards rather than a partial update: the party has moved
   * to a location in a region the client has never seen, so the scene, the
   * chart and the party rail all have to be re-read. Descending is rare and a
   * full reload of the screen is cheaper to reason about than a patch.
   */
  function doDescend() {
    return guarded(async () => {
      const r = await API.post('location/descend', {});
      if (r.message) log(r.message, 'important');
      if (r.depth) log(`Level ${r.depth}. ${r.rooms} rooms.`, '', 'story');
      await refreshAll();
    });
  }

  /** Back up to the Mouth. The floor they were on stops existing. */
  function doLeaveDungeon() {
    return guarded(async () => {
      const r = await API.post('location/leave_dungeon', {});
      if (r.message) log(r.message, 'success');
      await refreshAll();
    });
  }

  function doShortRest() {
    return guarded(async () => {
      const r = await API.post('location/short_rest', {});
      if (r.message) log(r.message, 'success');
      await refreshAll();
    });
  }

  /**
   * Read what is pinned to the board.
   *
   * Everything posted becomes work the party knows of, and the journal opens on
   * it — reading a board and then having to go and look somewhere else for what
   * was on it is not how a notice works.
   */
  function readBoard() {
    return guarded(async () => {
      const r = await API.post('quest/board', {});
      const posted = r.board || [];
      log(posted.length
        ? `The board has ${posted.length} job${posted.length === 1 ? '' : 's'} on it.`
        : 'The board is bare. Somebody has taken everything worth taking.', 'important');
      await refreshAll();
      if (posted.length && window.UI && window.UI.openJournal) window.UI.openJournal();
    });
  }

  /** Open a conversation. Meeting somebody is what puts their name on them. */
  async function talkTo(npcId) {
    try {
      const r = await API.get('dialogue/npc', { npc_id: npcId });
      state.knownNpcIds = r.known_npc_ids || state.knownNpcIds;
      // Meeting them puts their real name on the rail; mark them known in the
      // scene payload too so the row updates when the conversation closes.
      if (state.location && state.location.npcs) {
        state.location.npcs.forEach((n) => {
          if (+n.id === +npcId) n.known = true;
        });
      }
      if (window.Dialog) {
        await window.Dialog.open(r.npc);
        renderPeopleRail();
      }
    } catch (e) {
      showError(e.message);
    }
  }

  async function startCombat(payload) {
    try {
      const r = await API.post('combat/start', payload);
      state.combat = r.combat;
      state.mode = 'combat';
      render();
    } catch (e) {
      showError(e.message);
    }
  }

  /**
   * Ask the pit for a fight.
   *
   * `combat/pit` builds the match and starts it in the same request, so what
   * comes back is already a combat session — there is no encounter id to hold
   * on to and nothing to re-roll.
   */
  async function takeBout(tier) {
    try {
      const r = await API.post('combat/pit', { tier });
      state.combat = r.combat;
      state.mode = 'combat';
      render();
    } catch (e) {
      showError(e.message);
    }
  }

  // =========================================================================
  // Keyboard
  //
  // The point of a menu-driven game: numbers walk, letters act. Bound once,
  // globally, and inert while a text field has focus or another screen owns
  // the keyboard.
  // =========================================================================

  function bindKeys() {
    document.addEventListener('keydown', (e) => {
      if (isTypingTarget(e.target)) return;
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      if (state.mode === 'combat') return;
      // A conversation, a die prompt or a modal owns the keyboard while it is
      // open; the scene underneath must not also answer.
      if (document.querySelector('#dialogue-root:not(.hidden)')) return;
      if (document.querySelector('#check-root:not(.hidden)')) return;
      if (document.querySelector('#modal-root:not(.hidden)')) return;

      const loc = state.location;
      if (!loc) return;

      // There is no number key for travel any more. It indexed this scene's
      // exits, so the same digit meant a different place in every room, and the
      // map had to print it beside a name to be usable at all. Travel is
      // clicking a place on the chart.

      // Walking a floor, the arrows are the whole of the controls — and they
      // are bound only while the first-person view is up, so nothing else on
      // the page loses them.
      if (fpOn()) {
        switch (e.key) {
          case 'ArrowUp': e.preventDefault(); fpForward(); return;
          case 'ArrowLeft': e.preventDefault(); fpTurn(-1); return;
          case 'ArrowRight': e.preventDefault(); fpTurn(1); return;
          case 'ArrowDown': e.preventDefault(); fpTurn(2); return;
          default: break;
        }
      }

      switch (e.key.toLowerCase()) {
        case 'v':
          if (fpAvailable()) { e.preventDefault(); fpFlip(); }
          break;
        case 's': e.preventDefault(); doSearch(); break;
        case 'l': {
          const it = (loc.items || [])[0];
          if (it) { e.preventDefault(); doLoot(it.location_item_id); }
          break;
        }
        case 'r':
          // Same as the button above it: R opens the fire, it does not sleep
          // through it. Taking a room is still immediate — an innkeeper is a
          // transaction, not a scene.
          if (loc.inn_cost !== null && loc.inn_cost !== undefined) {
            e.preventDefault();
            doRest();
          } else if (loc.allow_camp) {
            e.preventDefault();
            openCampScreen();
          }
          break;
        case 'b':
          if (loc.job_board) {
            e.preventDefault();
            readBoard();
          }
          break;
        case 'f':
          if (loc.encounter) {
            e.preventDefault();
            startCombat({ encounter_id: loc.encounter.id });
          }
          break;
        case 't': {
          // Talk to the first authored person here; the crowd is a click away.
          const who = (loc.npcs || []).find((n) => !n.is_ambient) || (loc.npcs || [])[0];
          if (who) { e.preventDefault(); talkTo(who.id); }
          break;
        }
        case 'i':
          // The description. `m` used to open the chart here — the chart is
          // the scene now, so the keystroke worth having is the one that
          // opens what the chart replaced.
          e.preventDefault();
          openDescription();
          break;
        case 'j':
          e.preventDefault();
          if (window.UI && window.UI.openJournal) window.UI.openJournal();
          break;
        default: break;
      }
    });
  }

  // =========================================================================
  // Boot
  // =========================================================================

  async function boot() {
    bindDelegates();
    bindKeys();
    bindTopbar();

    // Whether the last floor was walked or read. Harmless anywhere there is no
    // raster to draw, because fpOn() asks for one before it asks for this.
    try { state.fp.on = window.localStorage.getItem(FP_PREF) === '1'; } catch (e) { /* private mode */ }
    if (window.UI && window.UI.init) window.UI.init();

    // The combat screen's own setup: the rules tables it draws slot dots and
    // bonus-action buttons from, and the animation index. Nothing called this,
    // so `rules` stayed null for the whole session — and slotTable() answering
    // zero for every class is not a cosmetic loss. The spell picker prices
    // every levelled spell against it, so each one came up "0 left" and
    // disabled: a Cleric with five spells and two slots on her sheet could
    // open the Cast list and take nothing out of it but a cantrip.
    if (window.Combat && window.Combat.init) {
      await window.Combat.init();
    }

    let status;
    try {
      status = await API.get('session/status');
    } catch (_) {
      window.location.href = 'login.php';
      return;
    }
    if (!status.character_id) {
      window.location.href = 'index.php';
      return;
    }
    state.partyId = status.party_id || null;

    await refreshAll();
    if (!state.location) return;

    announceArrival();
    // An ambush the party is already standing in front of — because they were
    // dropped here by a defeat, or closed the tab mid-fight — is offered on
    // load rather than waiting for them to travel somewhere and come back.
    if (state.mode !== 'combat' && state.location.encounter) {
      await handleEvents([{
        type: 'encounter',
        encounter_id: state.location.encounter.id,
        name: state.location.encounter.name,
      }]);
    }
  }

  function bindTopbar() {
    const on = (sel, fn) => {
      const el = $(sel);
      if (el) el.addEventListener('click', fn);
    };
    on('#btn-refresh', () => refreshAll());
    on('#btn-journal', () => window.UI && window.UI.openJournal && window.UI.openJournal());
    on('#btn-rolls', () => window.UI && window.UI.openRolls && window.UI.openRolls());
    on('#btn-camp', () => window.UI && window.UI.openCamp && window.UI.openCamp());
    on('#btn-fullscreen', () => {
      if (document.fullscreenElement) document.exitFullscreen();
      else document.documentElement.requestFullscreen().catch(() => {});
    });
  }

  // =========================================================================

  window.Game = {
    state,
    TILE_CACHE_VER,
    // utilities the other modules are written against
    esc, fmtMod, hpPct, hpBand, sleep, reducedMotion, isTypingTarget,
    log, showError,
    // portrait paths
    withTileVer, bodySpriteUrl, variantUrl, spriteArtUrl, classArtUrl,
    // state and flow
    refreshAll, render, renderLocation, mergeCharacter, setActiveCharacter,
    loadAvatars, mapFadeThrough,
    // actions
    travelTo, talkTo, startCombat, handleEvents,
    search: doSearch, loot: doLoot, rest: doRest, camp: doCamp,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
