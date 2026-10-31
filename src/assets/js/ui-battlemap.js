/**
 * The tactical map: sixteen by twelve cells of five feet, drawn as inline SVG.
 *
 * Follows ui-map.js's conventions, because they were right there and they are
 * right here for the same reasons: one template literal, a fixed unit viewBox
 * so nothing in JavaScript ever knows a pixel, `<g transform="translate">` per
 * token, an invisible hit shape so the click target is a finger rather than a
 * glyph, and `role="button" tabindex="0"` because an SVG group is not a button.
 *
 * The viewBox is `0 0 16 12` — one cell is one unit. That is ui-map's trick
 * applied exactly, and it lands better here because the authoring space *is*
 * the game space: the combatant at cell (3,7) is drawn at translate(3.5 7.5)
 * and no conversion exists to get wrong.
 *
 * WHAT THIS FILE DOES NOT DO IS GEOMETRY. Not one distance, path, cone or
 * cover check is computed here. Everything painted below arrives in
 * `state.ui`, worked out by the same functions on the server that decide
 * whether a move is legal — so the highlight and the rule cannot disagree.
 * ui-combat.js used to mirror CombatEngine::legalTargets in JavaScript and
 * carried a comment admitting the copy would drift; mirroring a pathfinder
 * would be that mistake several times over, and its failure mode is the worst
 * kind, a board that offers a move the server then refuses.
 *
 * ZOOM AND PAN, on the terms this file's header used to refuse them. It said a
 * board sixteen cells across fits legibly in its column, which is true, and
 * that the cost would be a viewBox to preserve across redraws and a second copy
 * of ui-map's pointer machine — and that if the day came, the answer was to
 * extract ui-map's controls rather than copy them. So: svg-view.js is that
 * extraction, both charts use it, and there is still one implementation.
 *
 * The other half of the refusal stands, and shapes the controls. The board is
 * legible unzoomed, so zooming is for leaning in rather than for finding your
 * way, and it must never be in the way of playing: a left-click on a cell is a
 * move and cannot also be a drag. Hence SPACE to pan — the modifier every
 * drawing program uses for exactly this reason — with the middle button as the
 * other way in, and the wheel to zoom.
 *
 * The viewBox is preserved across redraws in `VIEWS`, keyed by the container
 * rather than by the SVG, because render() throws the SVG away on every action
 * and a camera that snapped back to fitted after each swing would be worse than
 * no camera.
 *
 * THE DIORAMA ('tilt' and 'low' views) wraps this same SVG in a plane tilted
 * back in CSS 3D and stands the figures and tall props up on it as cards. The
 * SVG is not redrawn for it and stays the thing every click lands on; 'flat'
 * is the board exactly as it was. See the section of that name below.
 */
(function () {
  const esc = (s) => window.Game.esc(s);

  /**
   * The board's size, in cells.
   *
   * Not constants any more. Sixteen by twelve is what BattleMapGen builds and
   * what most fights are still fought on, but a fight inside a delve room is
   * fought on THE ROOM — see RoomBattleMap — so the board is whatever shape
   * that room is, up to about twice this. They are read off the terrain at the
   * top of every render rather than passed around, because every one of the
   * dozen places below that wants them is downstream of that point, and
   * threading two numbers through all of them would be noise.
   *
   * The values here are the fallback for a caller that asks about the board
   * before one has been drawn.
   */
  let W = 16;
  let H = 12;

  /** Take the board's dimensions from the terrain the server sent. */
  function sizeFrom(terrain) {
    const rows = Array.isArray(terrain) ? terrain : [];
    H = rows.length || 12;
    W = (rows[0] || '').length || 16;
  }

  /** Unique pattern ids so a gallery of boards on one page do not share fills. */
  let patternSeq = 0;

  function artUrl(path) {
    if (!path) return '';
    const ver = (window.Game && window.Game.TILE_CACHE_VER)
      ? `?v=${window.Game.TILE_CACHE_VER}` : '';
    return `/assets/images/${path}${ver}`;
  }

  /**
   * The figure sheets, once, from the static catalogue.
   *
   * Null until the fetch settles, then a map of art key and bestiary alias
   * onto the row. A missing file is not asked for twice: once this is loaded,
   * a key it does not know draws the face instead of a request that would
   * come back as the homepage.
   */
  let figureIndex = null;
  let figureWait = null;

  function indexFigures(data) {
    const by = Object.create(null);
    if (!data) return by;
    for (const row of [...(data.monsters || []), ...(data.heroes || [])]) {
      if (!row) continue;
      if (row.key) by[row.key] = row;
      if (row.monster_key) by[row.monster_key] = row;
    }
    const aliases = data.aliases || {};
    for (const from of Object.keys(aliases)) {
      const row = by[aliases[from]];
      if (row) by[from] = row;
    }
    return by;
  }

  function loadFigures() {
    if (figureWait) return figureWait;
    figureWait = fetch('/assets/images/figures/monsters.json')
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => { figureIndex = indexFigures(data); })
      .catch(() => { figureIndex = Object.create(null); });
    return figureWait;
  }

  /** Run once the catalogue is in, so a board drawn early can redraw at the real sizes. */
  function whenFigures(cb) {
    const run = () => { if (figureIndex) cb(); };
    if (figureIndex) { setTimeout(run, 0); return; }
    loadFigures().then(run);
  }

  loadFigures();

  /** Heights in cells. Large and Huge are the picture's footprint; the cell is still one. */
  const FIGURE_H = { tiny: 0.5, small: 0.75, medium: 1, large: 2, huge: 3 };
  /** Feet on the ring: 6px up a 40px cell, so a Medium body overhangs the top. */
  const FEET_Y = 0.5 - 6 / 40;
  /** The stirge hovers. Another 14/40 of a cell above the ring. */
  const TINY_LIFT = 14 / 40;
  /** Soles sit 4px above the bottom of a 320-tall sheet. */
  const SOLE = 4 / 320;

  function knownSize(word) {
    const s = String(word || '').trim().toLowerCase();
    return FIGURE_H[s] ? s : '';
  }

  /**
   * Which sheet, and how tall.
   *
   * Payload `size` wins when it is one of the five words the sheets are drawn
   * at. Otherwise the catalogue, and otherwise Small when the key or the race
   * says dwarf, gnome or halfling. Gargantuan is not a picture size — the
   * catalogue's own word is used, and nothing is drawn 4×4.
   */
  function figureFor(c, key) {
    if (!key) return null;
    const row = figureIndex ? figureIndex[key] : null;
    if (figureIndex && !row) return null;
    const payload = knownSize(c.size);
    const fromRow = row ? knownSize(row.size) : '';
    let size = payload || fromRow;
    if (!size) {
      const blob = `${key} ${c.race || ''} ${c.sprite_key || ''}`.toLowerCase();
      size = (blob.includes('dwarf') || blob.includes('gnome') || blob.includes('halfling'))
        ? 'small' : 'medium';
    }
    const height = FIGURE_H[size];
    const wide = !!(row && (row.wide || (row.canvas && row.canvas[0] >= 320)));
    const aspect = wide ? 1 : 192 / 320;
    return {
      size,
      height,
      footprint: height >= 2 ? height : 1,
      aspect,
      file: (row && row.file) || `${key}.png`,
    };
  }

  /** The full-body "style b" sheet for a combatant, or '' when the catalogue has none. */
  function figureUrl(c, key) {
    const row = figureIndex && key ? figureIndex[key] : null;
    return row && row.file ? '/assets/images/figures/' + row.file : '';
  }

  /**
   * A hand-drawn board carries its own picture — see FixedBattleMaps.php.
   *
   * Two things follow from `grid.image` being set. The picture is laid over the
   * floor at the board's full size and stretched to it rather than fitted:
   * the crop was cut to the board's proportions on the way in, so `none` is
   * honest here and `meet` would letterbox a picture that already fits.
   *
   * And the terrain stops painting itself. A generated board draws its walls
   * because nothing else would; a drawn board already has walls in the picture,
   * and a stone texture laid over the artist's is just a stone texture. The
   * cells stay exactly where they are — they are what the rules read and what
   * the pointer hits — they simply go quiet, which `.is-drawn` does in the
   * stylesheet.
   */

  /** Terrain character to a class, and to the words the tooltip uses. */
  const TERRAIN = {
    ',': ['is-rough', 'rough ground — costs double to cross'],
    '~': ['is-water', 'shallow water — costs double to cross'],
    'o': ['is-low', 'low cover — +2 AC to anyone behind it; blocks movement, not sight'],
    'n': ['is-tall', 'tall cover — +5 AC to anyone behind it; blocks movement and sight'],
    '#': ['is-wall', 'a wall'],
    'v': ['is-pit', 'a pit — nothing walks across it'],
  };

  const key = (x, y) => `${x},${y}`;

  function parseKey(k) {
    const p = String(k).split(',');
    return [parseInt(p[0], 10), parseInt(p[1], 10)];
  }

  // =========================================================================
  // Drawing
  // =========================================================================

  /**
   * The whole board as one SVG string.
   *
   * `opts.faceUrl` resolves a combatant to its portrait. Passed in rather than
   * worked out here so that there is one art resolver on the board and it is
   * the one ui-combat.js already had.
   */
  /**
   * Bring a board saved by an older generator up to today's look.
   *
   * A fight is stored with its board, so one started before the set pieces
   * and decals (#32) keeps the old dressing for as long as it lasts: bones
   * and rocks as side-view sprites on rough ground, which stand up off the
   * floor like something to fight, and long tables laid north to south with
   * no `rot`. Paint only — the terrain, which is what the rules read, is not
   * touched — and current generators still put the same two sprites on rough
   * ground in some palettes, so this is a permanent translation, not a
   * one-off. The saved state is never modified; a copy is.
   */
  const LEGACY_DECAL = {
    'decor/bones.png': 'battlemap/decals/bones.png',
    'decor/rocks.png': 'battlemap/decals/rubble.png',
  };
  function modernize(grid) {
    const props = Array.isArray(grid.props) ? grid.props : [];
    if (!props.some((p) => p && (LEGACY_DECAL[p.src] || legacyRun(p)))) return grid;
    const decals = Array.isArray(grid.decals) ? grid.decals.slice() : [];
    const taken = new Set(decals.map((d) => `${d.x},${d.y}`));
    const kept = [];
    for (const p of props) {
      const flat = p && LEGACY_DECAL[p.src];
      if (!flat) {
        kept.push(legacyRun(p) ? { ...p, rot: 90 } : p);
        continue;
      }
      const x = Number(p.x) || 0;
      const y = Number(p.y) || 0;
      if (taken.has(`${x},${y}`)) continue;
      taken.add(`${x},${y}`);
      decals.push({ x, y, src: flat, kind: 'rough', s: 1, rot: ((x * 7 + y * 13) % 4) * 90 });
    }
    return { ...grid, props: kept, decals };
  }
  /** A long piece laid north to south by a generator that did not say so. */
  function legacyRun(p) {
    const up = p && UPRIGHT[p.src];
    return !!(up && up.long && Number(p.rot) !== 90 && (Number(p.h) || 1) > (Number(p.w) || 1));
  }

  function html(state, opts) {
    const grid = modernize(state.grid || {});
    const terrain = grid.terrain || [];
    sizeFrom(terrain);
    const ui = state.ui || null;
    const o = opts || {};

    const pid = ++patternSeq;
    const floorId = `bm-fl-${pid}`;
    const wallId = `bm-wl-${pid}`;
    const waterId = `bm-wt-${pid}`;

    // The diorama is decided here, before a line of the SVG is written,
    // because the SVG has to know which of its things are standing up as
    // cards (and so hide their flat copies). In flat mode `deck` is null and
    // every interpolation below that reads it adds nothing at all — the markup
    // is byte for byte what it was before tilt existed.
    const mode = o.view || viewMode();
    // Turned (see turnFor) is decided against the box fit() last measured,
    // because the plane's shape and every card's position depend on it.
    let turned = false;
    if (mode !== 'flat') {
      turned = turnFor(mode, lastBox, lastTurned);
      lastTurned = turned;
    }
    const deck = mode !== 'flat' ? deal(state, o, grid, turned) : null;

    const svg = `<svg class="battlemap" viewBox="0 0 ${W} ${H}"
                 role="img" aria-label="The battlefield">
      <defs>
        <clipPath id="bm-round"><circle cx="0" cy="0" r="0.34"/></clipPath>
        <pattern id="bm-hatch" width="0.5" height="0.5" patternUnits="userSpaceOnUse"
                 patternTransform="rotate(45)">
          <line x1="0" y1="0" x2="0" y2="0.5" stroke-width="0.12"/>
        </pattern>
        ${grid.floor ? `<pattern id="${floorId}" width="1" height="1" patternUnits="userSpaceOnUse">
          <image href="${esc(artUrl(grid.floor))}" width="1" height="1"
                 preserveAspectRatio="xMidYMid slice"/></pattern>` : ''}
        ${grid.wall ? `<pattern id="${wallId}" width="1" height="1" patternUnits="userSpaceOnUse">
          <image href="${esc(artUrl(grid.wall))}" width="1" height="1"
                 preserveAspectRatio="xMidYMid slice"/></pattern>` : ''}
        <radialGradient id="bm-glow-grad">
          <stop offset="0" stop-color="#ffcf7a" stop-opacity="0.7"/>
          <stop offset="0.45" stop-color="#ff9a3c" stop-opacity="0.28"/>
          <stop offset="1" stop-color="#ff8a2a" stop-opacity="0"/>
        </radialGradient>
        <radialGradient id="bm-flame-grad" cx="0.5" cy="0.65" r="0.6">
          <stop offset="0" stop-color="#fff3c4"/>
          <stop offset="0.5" stop-color="#ffb347"/>
          <stop offset="1" stop-color="#e2531c" stop-opacity="0"/>
        </radialGradient>
        <radialGradient id="bm-vignette-grad" cx="0.5" cy="0.5" r="0.72">
          <stop offset="0.55" stop-color="#000" stop-opacity="0"/>
          <stop offset="1" stop-color="#000" stop-opacity="0.5"/>
        </radialGradient>
        <pattern id="${waterId}" width="1" height="1" patternUnits="userSpaceOnUse">
          <image href="${esc(artUrl('tiles/water.png'))}" width="1" height="1"
                 preserveAspectRatio="xMidYMid slice"/></pattern>${deck ? `
        <radialGradient id="bm-shadow-grad">
          <stop offset="0" stop-color="#0a0500" stop-opacity="0.6"/>
          <stop offset="0.6" stop-color="#0a0500" stop-opacity="0.3"/>
          <stop offset="1" stop-color="#0a0500" stop-opacity="0"/>
        </radialGradient>` : ''}
      </defs>
      <rect class="bm-floor" x="0" y="0" width="${W}" height="${H}"
            ${grid.floor ? `style="fill:url(#${floorId})"` : ''}/>
      ${grid.image ? `<image class="bm-art" href="${esc(artUrl(grid.image))}"
            x="0" y="0" width="${W}" height="${H}" preserveAspectRatio="none"/>` : ''}
      <g class="bm-decals">${decalsHtml(grid)}</g>
      <g class="bm-terrain${grid.image ? ' is-drawn' : ''}">${
        terrainHtml(terrain, { wallId, waterId, hasWall: !grid.image && !!grid.wall, dressed: dressedCells(grid) })
      }</g>
      <g class="bm-props">${propsHtml(grid, deck)}</g>${deck ? `
      <g class="bm-shadows" pointer-events="none">${deck.shadows}</g>` : ''}
      <g class="bm-light" pointer-events="none">${lightHtml(grid, deck)}</g>
      <g class="bm-zones">${zonesHtml(grid, state)}</g>
      <g class="bm-reach">${reachHtml(ui)}</g>
      <g class="bm-threat">${threatHtml(ui)}</g>
      <g class="bm-aoe"></g>
      <g class="bm-path"></g>
      <g class="bm-cells">${cellsHtml(terrain)}</g>
      <g class="bm-cover-marks">${coverMarksHtml(terrain)}</g>
      <g class="bm-grid">${gridHtml()}
        <rect class="bm-edge" x="0" y="0" width="${W}" height="${H}"/></g>
      <g class="bm-tokens">${tokensHtml(state, o, deck)}</g>
      <g class="bm-motes" pointer-events="none">${motesHtml(grid)}</g>
      <g class="bm-shots"></g>
      <g class="bm-floats"></g>
    </svg>`;

    if (!deck) return svg;
    const deg = lastDeg[`${mode}${turned ? '+t' : ''}`] || VIEW_TILT[mode];
    return `<div class="bm-stage is-${mode}${turned ? ' is-turned' : ''}" style="--tilt:${deg}deg">
      <div class="bm-plane">${svg}${wallsHtml(grid, deck)}
        <div class="bm-cards">${deck.cards}</div>
      </div>
    </div>`;
  }

  function terrainHtml(terrain, tex) {
    let out = '';
    for (let y = 0; y < H; y++) {
      const row = terrain[y] || '';
      for (let x = 0; x < W; x++) {
        const ch = row[x];
        const spec = TERRAIN[ch];
        if (!spec) continue;
        if (ch === '#' && tex && tex.hasWall) {
          out += `<rect class="bm-cell is-wall" x="${x}" y="${y}" width="1" height="1"
            style="fill:url(#${tex.wallId})"/>`;
          continue;
        }
        if (ch === '~') {
          out += `<rect class="bm-cell is-water" x="${x}" y="${y}" width="1" height="1"
            style="fill:url(#${tex.waterId})"/>`;
          continue;
        }
        // A cover cell with furniture drawn on it is the furniture; the grey
        // slab under a barrel only made the barrel look like it sat in a wall.
        const dressed = tex && tex.dressed && tex.dressed.has(key(x, y));
        out += `<rect class="bm-cell ${spec[0]}${dressed ? ' is-dressed' : ''}" x="${x}" y="${y}" width="1" height="1"/>`;
      }
    }
    return out;
  }

  /** The cells some prop covers, so the terrain can stop drawing a slab there. */
  function dressedCells(grid) {
    const out = new Set();
    for (const p of (Array.isArray(grid.props) ? grid.props : [])) {
      const w = Math.max(1, Number(p.w) || 1);
      const h = Math.max(1, Number(p.h) || 1);
      for (let j = 0; j < h; j++) for (let i = 0; i < w; i++) out.add(key((Number(p.x) || 0) + i, (Number(p.y) || 0) + j));
    }
    return out;
  }

  /**
   * Flat pictures lying on the floor — rubble on rough ground, cracks and
   * stains on open ground. Paint, never a rule: the server's `decals` list is
   * art only, drawn under the terrain and the furniture and taking no clicks,
   * so nothing here can ever be mistaken for something standing up.
   */
  function decalsHtml(grid) {
    const list = Array.isArray(grid.decals) ? grid.decals : [];
    return list.map((d) => {
      const x = Number(d.x) || 0;
      const y = Number(d.y) || 0;
      const s = Math.max(0.3, Math.min(1.6, Number(d.s) || 1));
      const rot = Number(d.rot) || 0;
      const kind = d.kind === 'floor' ? 'is-floor' : 'is-rough';
      return `<image class="bm-decal ${kind}" href="${esc(artUrl(d.src))}"
        x="${(x + 0.5 - s / 2).toFixed(3)}" y="${(y + 0.5 - s / 2).toFixed(3)}"
        width="${s.toFixed(3)}" height="${s.toFixed(3)}"
        transform="rotate(${rot} ${x + 0.5} ${y + 0.5})"
        preserveAspectRatio="xMidYMid meet" pointer-events="none"
        onerror="this.remove()"/>`;
    }).join('');
  }

  /**
   * A small shield on each cover cell, shown while the pointer is on it.
   *
   * bind() lights the one under the pointer (`is-on`); a token in cover
   * carries the same shield, shown while it is hovered. The number is the most the obstacle can give, the same number the
   * tooltip and coverBetween() use.
   */
  function coverMarksHtml(terrain) {
    let out = '';
    for (let y = 0; y < H; y++) {
      const row = terrain[y] || '';
      for (let x = 0; x < W; x++) {
        const ch = row[x];
        if (ch !== 'o' && ch !== 'n') continue;
        out += shieldHtml(x + 0.5, y + 0.5, ch === 'n' ? 5 : 2, 'bm-cover-mark', ` data-cover-at="${x},${y}"`);
      }
    }
    return out;
  }

  function shieldHtml(cx, cy, bonus, cls, attrs) {
    return `<g class="${cls}"${attrs || ''} transform="translate(${cx} ${cy})" pointer-events="none">
      <path class="bm-shield" d="M0,-0.26 L0.22,-0.17 L0.2,0.06 Q0.14,0.2 0,0.28 Q-0.14,0.2 -0.2,0.06 L-0.22,-0.17 Z"/>
      <text class="bm-shield-num" y="0.08">+${bonus}</text></g>`;
  }

  // =========================================================================
  // Atmosphere: torchlight and dust. Paint that moves, never a rule.
  //
  // Positions come from a tiny generator seeded by the board's own seed, so
  // the torches are where they were last turn (render() redraws the whole SVG
  // after every action) and a fight looks the same each time it is loaded.
  // The flicker itself is CSS, with per-element durations and negative delays
  // so nothing pulses in step. prefers-reduced-motion stops all of it and
  // leaves the light where it is.
  // =========================================================================

  function seeded(seed) {
    let t = (Number(seed) >>> 0) || 1;
    return () => {
      t = (t + 0x6D2B79F5) >>> 0;
      let r = Math.imul(t ^ (t >>> 15), 1 | t);
      r = (r + Math.imul(r ^ (r >>> 7), 61 | r)) ^ r;
      return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
    };
  }

  /** Palettes lit by fire rather than by the sky. */
  const INDOOR = new Set(['interior', 'cavern', 'tunnel', 'arena', 'fixed']);

  /**
   * Where the torches are, in cell units.
   *
   * A drawn board names its own (`grid.lights`, the sconces in the picture).
   * A walled board hangs one every few cells on a rim wall that faces the
   * floor. An unwalled indoor board — the pit — gets them just off its top and
   * bottom edge, which is where the walls of the room it stands in would be.
   */
  function torchSpots(grid, rnd) {
    if (Array.isArray(grid.lights)) {
      return grid.lights.map((l) => [Number(l[0]) || 0, Number(l[1]) || 0]);
    }
    if (!INDOOR.has(grid.palette)) return [];
    const t = grid.terrain || [];
    const wall = (x, y) => (t[y] || '')[x] === '#';
    const spots = [];
    const start = 1 + Math.floor(rnd() * 3);
    for (let x = start; x < W - 1; x += 4 + Math.floor(rnd() * 2)) {
      for (const [y, inner, at] of [[0, 1, 0.35], [H - 1, H - 2, H - 0.35]]) {
        if (wall(x, y) && !wall(x, inner)) spots.push([x + 0.5, at]);
        else if (!wall(x, y) && grid.palette === 'arena') spots.push([x + 0.5, y === 0 ? 0.08 : H - 0.08]);
      }
    }
    return spots;
  }

  /**
   * Every torch with its flicker timing, or null for a board that brings its
   * own light in the picture.
   *
   * One list for both drawings — the floor glow here and, in the diorama, the
   * sconce on the back wall — so the two cannot disagree about where a torch
   * is or how fast it gutters. The dice are rolled in the order lightHtml()
   * always rolled them, which keeps the flat board exactly as it was.
   */
  function torchPlan(grid) {
    if (grid.image && !Array.isArray(grid.lights) && grid.palette !== 'fixed') return null;
    const rnd = seeded((Number(grid.seed) || 0) ^ 0x51ed);
    return torchSpots(grid, rnd).map(([x, y]) => ({
      x,
      y,
      dur: (1.6 + rnd() * 1.4).toFixed(2),
      delay: (-rnd() * 3).toFixed(2),
    }));
  }

  /** Is this torch on the board's back or front rim, where the diorama has a wall or a lip? */
  const torchOnRim = (t) => t.y < 1.2 || t.y > H - 1.2;

  function lightHtml(grid, deck) {
    const plan = torchPlan(grid);
    if (!plan) return '';
    const indoor = INDOOR.has(grid.palette);
    let out = indoor
      ? `<rect class="bm-vignette" x="0" y="0" width="${W}" height="${H}" fill="url(#bm-vignette-grad)"/>`
      : '';
    for (const t of plan) {
      const { x, y } = t;
      const anim = `style="animation-duration:${t.dur}s;animation-delay:${t.delay}s"`;
      // Tilted, a torch on the back rim is a sconce on the back wall (see
      // wallsHtml) and one on the front rim would be a flame lying on the
      // floor at the viewer's feet; both keep the pool of light they cast and
      // lose the flat flame. Turned, the old top and bottom rims are the side
      // walls and the back wall is the old left edge, so any edge counts.
      const rim = deck && deck.turned
        ? torchOnRim(t) || t.x < 1.2 || t.x > W - 1.2
        : torchOnRim(t);
      const flame = deck && deck.walled && rim ? '' : `
        <g transform="translate(${x.toFixed(2)} ${y.toFixed(2)})">
          <ellipse class="bm-flame" cx="0" cy="-0.04" rx="0.1" ry="0.17"
            fill="url(#bm-flame-grad)" ${anim}/></g>`;
      out += `<circle class="bm-glow" cx="${x.toFixed(2)}" cy="${y.toFixed(2)}" r="3"
          fill="url(#bm-glow-grad)" ${anim}/>${flame}`;
    }
    return out;
  }

  /**
   * Dust in the torchlight, pollen out of doors: a dozen and a half specks
   * that drift and fade on long loops. Drawn over the tokens because dust is
   * in the air, and far too faint and small to hide anybody.
   */
  function motesHtml(grid) {
    const rnd = seeded((Number(grid.seed) || 0) ^ 0xd057);
    const kind = INDOOR.has(grid.palette) ? 'is-dust' : 'is-pollen';
    let out = '';
    for (let i = 0; i < 18; i++) {
      const x = (rnd() * W).toFixed(2);
      const y = (rnd() * H).toFixed(2);
      const r = (0.025 + rnd() * 0.03).toFixed(3);
      const dur = (9 + rnd() * 9).toFixed(1);
      const delay = (-rnd() * 18).toFixed(1);
      const dx = ((rnd() - 0.5) * 1.6).toFixed(2);
      out += `<circle class="bm-mote ${kind}" cx="${x}" cy="${y}" r="${r}"
        style="animation-duration:${dur}s;animation-delay:${delay}s;--mx:${dx}px"/>`;
    }
    return out;
  }

  /**
   * Furniture sitting on cover cells.
   *
   * The engine never reads these. Each sprite is a picture of the terrain
   * character already in that rectangle — half cover, tall cover, or rough
   * ground — so the highlight and the rule cannot disagree. `stand` sprites
   * grow north of their footprint because they are 3/4-view, not tiles.
   *
   * Painted before the hit-cells so a barrel never eats a click.
   */
  function propsHtml(grid, deck) {
    const list = Array.isArray(grid.props) ? grid.props.slice() : [];
    list.sort((a, b) => (a.y - b.y) || (a.x - b.x));
    return list.map((p) => {
      const w = Math.max(1, Number(p.w) || 1);
      const h = Math.max(1, Number(p.h) || 1);
      const x = Number(p.x) || 0;
      const y = Number(p.y) || 0;
      const pad = 0.06;
      // A prop standing up as a card in the diorama keeps its flat picture in
      // the markup, hidden, so that a card whose art fails can hand it back.
      const tag = deck ? deck.propCard.get(p) : null;
      const cls = tag ? `bm-prop has-card" data-card="${tag}` : 'bm-prop';
      if (Number(p.rot) === 90) {
        // Long art is drawn lying east to west; a north-south run is the same
        // picture turned a quarter about the middle of its footprint.
        const cx = x + w / 2;
        const cy = y + h / 2;
        return `<image class="${cls}" href="${esc(artUrl(p.src))}"
          x="${(cx - h / 2 + pad).toFixed(3)}" y="${(cy - w / 2 + pad).toFixed(3)}"
          width="${(h - pad * 2).toFixed(3)}" height="${(w - pad * 2).toFixed(3)}"
          transform="rotate(90 ${cx} ${cy})"
          preserveAspectRatio="xMidYMid meet"
          pointer-events="none"
          onerror="this.remove()"/>`;
      }
      const stand = p.stand ? Math.min(1.1, 0.55 + h * 0.2) : 0;
      return `<image class="${cls}" href="${esc(artUrl(p.src))}"
        x="${x + pad}" y="${y - stand + pad}"
        width="${w - pad * 2}" height="${h + stand - pad * 2}"
        preserveAspectRatio="xMidYMid meet"
        pointer-events="none"
        onerror="this.remove()"/>`;
    }).join('');
  }

  /**
   * The deployment zones, shown only before anybody has moved.
   *
   * They explain the starting formation — why the archer is at the back — and
   * then stop being true the moment somebody walks, so they are drawn in round
   * one and never again.
   */
  function zonesHtml(grid, state) {
    if ((state.round || 1) > 1 || !grid.zones) return '';
    return ['party', 'foe'].map((side) => {
      const z = grid.zones[side];
      if (!z) return '';
      return `<rect class="bm-zone is-${side}" x="${z[0]}" y="${z[1]}"
        width="${z[2] - z[0] + 1}" height="${z[3] - z[1] + 1}"/>`;
    }).join('');
  }

  function reachHtml(ui) {
    if (!ui || !ui.reach) return '';
    let out = '';
    for (const [k, v] of Object.entries(ui.reach)) {
      if (!v.end || !v.cost) continue;
      const [x, y] = parseKey(k);
      out += `<rect class="bm-step ${v.provokes ? 'is-provokes' : ''}"
        x="${x}" y="${y}" width="1" height="1"/>`;
    }
    return out;
  }

  function threatHtml(ui) {
    if (!ui || !ui.threat) return '';
    // A PHP array with no entries encodes as [], so this has to survive being
    // handed a list rather than an object.
    const cells = Array.isArray(ui.threat) ? [] : Object.keys(ui.threat);
    return cells.map((k) => {
      const [x, y] = parseKey(k);
      return `<rect class="bm-danger" x="${x}" y="${y}" width="1" height="1"/>`;
    }).join('');
  }

  /**
   * The cell lines, and the outline that says where the board stops.
   *
   * The lines used to be drawn at 7% white, which reads while the reach
   * highlight is tinting half the board and disappears the moment movement is
   * spent — leaving tokens floating on the panel's own background with no
   * visible squares. A game that prices everything in feet has to show the
   * ruler at all times, so the lines carry weight of their own now and the
   * board draws its own floor and border rather than borrowing the panel's.
   */
  function gridHtml() {
    let out = '';
    for (let x = 1; x < W; x++) out += `<line x1="${x}" y1="0" x2="${x}" y2="${H}"/>`;
    for (let y = 1; y < H; y++) out += `<line x1="0" y1="${y}" x2="${W}" y2="${y}"/>`;
    return out;
  }

  /**
   * One transparent rectangle per cell, purely to be clicked.
   *
   * A hundred and ninety-two rects is nothing, and it buys a click handler
   * that is a `dataset` read, free `:hover` in CSS and free keyboard focus —
   * the same bargain ui-map.js's invisible `.wm-hit` rects strike. Converting
   * a click position back into a cell with getScreenCTM would be arithmetic
   * that can be wrong.
   */
  function cellsHtml(terrain) {
    let out = '';
    for (let y = 0; y < H; y++) {
      const row = terrain[y] || '';
      for (let x = 0; x < W; x++) {
        const spec = TERRAIN[row[x]];
        const title = spec ? spec[1] : 'open ground';
        out += `<rect class="bm-hit" data-bm-cell="${x},${y}" x="${x}" y="${y}"
          width="1" height="1" tabindex="0" role="button"><title>${esc(title)}</title></rect>`;
      }
    }
    return out;
  }

  function faceImage(c, href) {
    if (c.token) {
      return `<image class="bm-face is-token" href="${href}" x="-0.46" y="-0.46"
           width="0.92" height="0.92" preserveAspectRatio="xMidYMid meet"
           onerror="this.remove()"/>`;
    }
    return `<image class="bm-face" href="${href}" x="-0.34" y="-0.34"
         width="0.68" height="0.68" clip-path="url(#bm-round)"
         preserveAspectRatio="xMidYMid slice"
         onerror="this.remove()"/>`;
  }

  /**
   * The full figure, feet on the ring, or the face when there is no sheet.
   *
   * The picture is seated on the server's anchor cell. Large and Huge are a
   * 2×2 and a 3×3 drawn around that cell; the server's footprint box is the cells
   * they occupy, and the hit target stays the ring so a Growth does not swallow
   * the squares beside it. The face is in the markup already and hidden —
   * a sheet that fails to decode reveals it, which is also what a token-pack
   * stamp falls back through.
   */
  function figureHtml(c, o) {
    const href = esc(o.faceUrl(c));
    const face = faceImage(c, href);
    const key = String((o.artKey ? o.artKey(c) : '') || c.sprite_key || '');
    const spec = figureFor(c, key);
    if (!spec) return { art: face, figure: false };

    const feetY = spec.size === 'tiny' ? FEET_Y - TINY_LIFT : FEET_Y;
    const imgH = spec.height;
    const imgW = spec.height * spec.aspect;
    const imgY = feetY - imgH * (1 - SOLE);
    const imgX = -imgW / 2;
    const n = (v) => Number(v).toFixed(3);
    // No outline until the catalogue has named the sheet. A guess would
    // put a 2×2 around a face that is about to fall back.
    const foot = figureIndex && spec.footprint > 1
      ? `<rect class="bm-foot" x="-0.5" y="-0.5"
           width="${n(spec.footprint)}" height="${n(spec.footprint)}"/>`
      : '';
    const held = face.replace('class="bm-face', 'class="bm-face is-held');
    const sheet = `<image class="bm-figure" href="${esc(artUrl('figures/' + spec.file))}"
         x="${n(imgX)}" y="${n(imgY)}" width="${n(imgW)}" height="${n(imgH)}"
         preserveAspectRatio="xMidYMax meet" image-rendering="pixelated"
         onerror="var g=this.parentNode;var f=g&amp;&amp;g.querySelector('.is-held');if(f)f.classList.remove('is-held');this.remove()"/>`;
    // The server's footprint is the anchor cell and the cells east and south
    // of it, so the picture is seated on that box rather than on the anchor.
    const seat = spec.footprint > 1
      ? ` transform="translate(${n((spec.footprint - 1) / 2)} ${n(spec.footprint - 1)})"` : '';
    return { art: `${foot}<g${seat}>${held}${sheet}</g>`, figure: true };
  }

  function tokensHtml(state, o, deck) {
    const ui = state.ui || null;
    const targets = (ui && ui.targets && !Array.isArray(ui.targets)) ? ui.targets : {};
    const actorCid = state.order ? state.order[state.turn_index] : null;

    return (state.combatants || []).map((c) => {
      if (c.x == null || c.y == null) return '';
      const dead = !c.alive;
      const downed = (c.conditions || []).some((k) => (typeof k === 'string' ? k : k.key) === 'unconscious');
      const shot = targets[c.cid];
      const pct = c.max_hp ? Math.max(0, Math.min(1, c.hp / c.max_hp)) : 0;

      // The same discipline the cards keep, and for the same reason the CSS
      // beside them spells out: one channel per fact. Side is the ring's
      // colour, selection is a second ring, whose turn it is is a pulsing
      // circle, and reachability is a class on the token rather than a fourth
      // colour on any of them.
      const cls = [
        'bm-token',
        c.side === 'party' ? 'is-party' : 'is-foe',
        c.cid === actorCid ? 'is-actor' : '',
        c.cid === o.selected ? 'is-selected' : '',
        dead ? 'is-dead' : '',
        downed ? 'is-downed' : '',
        shot ? 'in-reach' : '',
      ].filter(Boolean).join(' ');

      const note = shot
        ? ` — ${shot.dist} ft${shot.why ? ', ' + shot.why : ''}`
        : '';

      const drawn = figureHtml(c, o);
      const ringR = drawn.figure ? '0.40' : (c.token ? '0.47' : '0.40');
      const ringFill = (drawn.figure || c.token) ? ' fill="none"' : '';

      // Standing up as a card in the diorama: the token keeps its ring, its
      // turn pulse and its hit circle on the floor, hides its picture and its
      // bar (the card carries both), and casts the card's shadow from inside
      // the group, so the shadow travels with the token when it walks.
      const card = deck ? deck.tokenCard.get(c.cid) : null;
      const carded = card ? ` has-card" data-card="${card.tag}` : '';
      const shadow = card ? `
        <ellipse class="bm-shadow" cx="${card.sx.toFixed(3)}" cy="${card.sy.toFixed(3)}"
          rx="${card.rx.toFixed(3)}" ry="${card.ry.toFixed(3)}" fill="url(#bm-shadow-grad)"/>` : '';

      return `<g class="${cls}${c.token ? ' has-token' : ''}${drawn.figure ? ' has-figure' : ''}${carded}"
          transform="translate(${c.x + 0.5} ${c.y + 0.5})"
          data-cid="${esc(c.cid)}" ${dead ? '' : 'role="button" tabindex="0"'}>
        <title>${esc(c.name)} — HP ${esc(c.hp)}/${esc(c.max_hp)}, AC ${esc(c.ac)}${esc(note)}</title>${shadow}
        <circle class="bm-hit-token" r="0.48"/>
        <circle class="bm-turn" r="0.46"/>
        <g class="bm-body">
        <circle class="bm-ring" r="${ringR}"${ringFill}/>
        ${drawn.art}
        ${shot && Number(shot.cover) > 0 ? shieldHtml(0.34, -0.3, Number(shot.cover), 'bm-cover-badge') : ''}
        <rect class="bm-hp-back" x="-0.36" y="0.38" width="0.72" height="0.12" rx="0.06"/>
        <rect class="bm-hp ${pct > 0.5 ? 'ok' : pct > 0.25 ? 'mid' : 'low'}"
              x="-0.36" y="0.38" width="${(0.72 * pct).toFixed(3)}" height="0.12" rx="0.06"/>
        </g>
      </g>`;
    }).join('');
  }

  // =========================================================================
  // The diorama: the same board, tilted back, with things standing on it
  //
  // The SVG above is not redrawn for this, and that is the whole design. It is
  // laid in a `.bm-plane` the exact shape of the board, the plane is rotated
  // back in CSS 3D inside a `.bm-stage` that holds the perspective, and the
  // browser hit-tests clicks through that transform by itself — so every cell
  // rect, every token ring, reach, path, AoE, threat and cover mark is the
  // same element in the same cell units, and no line of this file un-projects
  // a pointer. What is NEW is only what stands up: walls on the board's rim,
  // and a card per figure and per tall prop, rotated back by the same angle so
  // that it faces the screen. Cards, walls and the lip are all
  // `pointer-events: none`; a click goes past them to the floor.
  //
  //   .bm-stage   perspective, and the jolt (outside the 3D chain)
  //   .bm-plane   rotateX(tilt), preserve-3d, sized by fit()
  //     svg       the board, unchanged, filling the plane (turned: rotated into it)
  //     .bm-wall  ×3 and .bm-lip, standing on / hanging from the rim
  //     .bm-cards preserve-3d; a card is rotateX(-tilt) about its foot
  //
  // SAFARI flattens a 3D context the moment anything in the chain has a
  // filter, opacity below 1, overflow other than visible, a blend mode, a
  // clip-path or mask, isolation or paint containment. So none of those go on
  // `.bm-plane`, `.bm-cards` or a card itself; the flicker, the hurt flash and
  // the torchlight on a figure all live on LEAVES — the `<img>` in a card, a
  // glow div inside a wall — and the board's jolt moves `.bm-stage`.
  //
  // Cards are positioned in CSS from their own foot point in cell units
  // (`--cx --cy`) and the camera's current viewBox (`--vbx --vby` and
  // `--cell`, px per cell), which syncVars() writes on every pan and zoom. So
  // the camera moves them without a redraw, and the arithmetic that keeps a
  // card on its cell is a subtraction and a multiply in the stylesheet.
  //
  // FILLING THE PANEL. The angle is not fixed: fit() picks one inside the
  // view's TILT_RANGE so the projected board's shape matches the panel's
  // (steeper is shorter), then scales to fill. And a landscape board in a
  // portrait panel — or the other way round — is drawn TURNED (`is-turned`):
  // the plane is H cells wide and W deep, and the SVG inside it is rotated a
  // quarter clockwise, so original cell (x, y) sits at view (H - y, x) and
  // column 0 is at the back. The SVG is still what is clicked; only the
  // cards, the shadows and the camera's numbers are converted, by toView()
  // and in syncVars(). Whether to turn is decided at html() time from the
  // last box fit() measured, and fit() asks for one re-render (bind()'s
  // `onReflow`) when the box says otherwise.
  // =========================================================================

  const VIEW_KEY = 'rm.board.view';
  /** Degrees the floor leans back, per view, before fit() adapts it. 'flat' is today's board. */
  const VIEW_TILT = { tilt: 50, low: 32 };
  /** The range fit() may lean each view through to match the panel's shape. */
  const TILT_RANGE = { tilt: [40, 60], low: [26, 40] };
  const VIEW_ORDER = ['tilt', 'low', 'flat'];

  /** Turn when that makes a cell this much bigger; turn back below the second. */
  const TURN_ON = 1.12;
  const TURN_OFF = 1.06;
  /** The panel fit() last measured, and what html() last decided from it. */
  let lastBox = null;
  let lastTurned = false;
  /** The angle fit() last chose per view (and turn), so a redraw opens on it. */
  const lastDeg = {};

  /**
   * The biggest cell a board vw wide and vd deep could get in this box, by
   * the orthographic estimate fit() starts from, over the view's angle range.
   */
  function bestCell(vw, vd, mode, box) {
    const [lo, hi] = TILT_RANGE[mode];
    let best = 0;
    for (let i = 0; i <= 4; i++) {
      const t = (lo + (hi - lo) * i / 4) * Math.PI / 180;
      best = Math.max(best, Math.min(
        box.w / (vw + 1),
        box.h / (vd * Math.cos(t) + WALL_H * Math.sin(t) + 1)
      ));
    }
    return best;
  }

  /**
   * Should this board be drawn turned in this box? A pure function of the
   * board, the view, the box and what is on screen now (`was`, for the
   * hysteresis that stops a box right at the threshold flapping).
   */
  function turnFor(mode, box, was) {
    if (!box || !box.w || !box.h || !TILT_RANGE[mode]) return false;
    const gain = bestCell(H, W, mode, box) / bestCell(W, H, mode, box);
    return gain > (was ? TURN_OFF : TURN_ON);
  }

  /** A footprint's top-left cell in the plane's frame; see "filling the panel". */
  function toView(x, y, fp, turned) {
    return turned ? [H - y - (fp || 1), x] : [x, y];
  }

  /** A point in the plane's frame back on the board (the SVG's own units). */
  function toBoard(vx, vy, turned) {
    return turned ? [vy, H - vx] : [vx, vy];
  }

  /**
   * The view the player chose, or the one their screen suits.
   *
   * A narrow column has the least height to spare and gains least from
   * standing things up, so a phone opens flat and a tablet at the shallower
   * angle; the stored choice wins either way.
   */
  function viewMode() {
    let stored = null;
    try { stored = window.localStorage.getItem(VIEW_KEY); } catch (_) { /* private mode */ }
    if (stored === 'flat' || VIEW_TILT[stored]) return stored;
    const w = window.innerWidth || 1024;
    return w >= 900 ? 'tilt' : w >= 600 ? 'low' : 'flat';
  }

  function setViewMode(m) {
    if (m !== 'flat' && !VIEW_TILT[m]) return;
    try { window.localStorage.setItem(VIEW_KEY, m); } catch (_) { /* not kept, still applied */ }
  }

  /** The view the toggle goes to next. */
  function nextViewMode(m) {
    const i = VIEW_ORDER.indexOf(m || viewMode());
    return VIEW_ORDER[(i + 1) % VIEW_ORDER.length];
  }

  /** Card height per cell of figure height. A Medium body is 2 cells tall standing: the tilt
   *  foreshortens the floor, not the figures, so a body one cell high reads as a toy. */
  const CARD_SCALE = 2.0;
  /** The same lift for furniture, so a pillar still stands taller than the people beside it. */
  const PROP_SCALE = 1.3;
  /** Feet a touch south of the ring's centre, so they sit on the ring rather than inside it. */
  const CARD_FOOT = 0.12;
  /** A standee's height over its disc: the disc, plus the stem and foot it stands on. */
  const STANDEE_H = 1.8;
  /** The back and side walls, in cells at zoom 1. */
  const WALL_H = 2.4;

  const UP = 'battlemap/upright/';

  /**
   * Which flat props stand up, and how.
   *
   * Keyed by the prop's flat `src` exactly as the server sends it; the value
   * names the upright picture (in battlemap/upright/) and its height in cells.
   * Width comes from the picture's own aspect once it loads — except for a
   * `long` piece, a run of wall or a table along its footprint, which is
   * exactly as wide as that footprint (×1.04, so a run reads as continuous)
   * and as tall as its aspect says, capped at `h`. A long piece turned north
   * to south (`rot: 90`) is seen end-on, so it stands as one `end` card per
   * cell instead; a long piece with no end art stays flat when turned.
   *
   * Anything not here — pits, beams, rubble, decals, trees, anything that
   * lies down — stays the flat SVG prop it was. That is deliberate rather than
   * unfinished: a pit standing up is a hole in the air.
   */
  const UPRIGHT = {};
  const upright = (srcs, spec) => srcs.forEach((s) => { UPRIGHT[s] = spec; });
  const PROPS = 'battlemap/props/';
  upright([PROPS + 'pillar.png', 'decor/column.png'], { file: 'pillar.png', h: 2.1 });
  upright([PROPS + 'pillar_broken.png'], { file: 'pillar_broken.png', h: 1.55 });
  upright([PROPS + 'barrels.png', 'decor/barrel_pile.png'], { file: 'barrels.png', h: 1.1 });
  upright([PROPS + 'crates.png', 'decor/crate_pile.png', 'decor/crate_stack.png'], { file: 'crates.png', h: 1.3 });
  upright([PROPS + 'weapon_rack.png'], { file: 'weapon_rack.png', h: 1.5 });
  upright([1, 2, 3].map((n) => `${PROPS}wall_low_${n}.png`), { file: 'wall_low.png', long: true, h: 0.9, end: 'wall_low_end.png' });
  upright([1, 2, 3].map((n) => `${PROPS}table_over_${n}.png`), { file: 'table_over.png', tile: true, h: 0.95, end: 'table_over_end.png' });
  upright([1, 2, 3].map((n) => `${PROPS}barricade_${n}.png`), { file: 'barricade.png', long: true, h: 1.0, end: 'barricade_end.png' });
  upright([PROPS + 'stalagmites.png'], { file: 'stalagmites.png', h: 1.5 });
  upright([PROPS + 'boulder.png', 'decor/boulder.png'], { file: 'boulder.png', h: 1.0 });
  upright(['decor/barrel.png'], { file: 'barrel.png', h: 0.95 });
  upright(['decor/keg.png'], { file: 'keg.png', h: 0.85 });
  upright(['decor/crate.png'], { file: 'crate.png', h: 0.85 });
  upright(['decor/chest.png'], { file: 'chest.png', h: 0.7 });
  upright(['decor/chair.png'], { file: 'chair.png', h: 1.0 });
  upright(['decor/table_plain.png', 'decor/table_tavern.png', 'decor/table_long.png'], { file: 'table.png', long: true, h: 0.85 });
  upright(['decor/pew.png', 'decor/pew_long.png'], { file: 'bench.png', long: true, h: 0.75 });
  upright(['decor/bookshelf_tall.png'], { file: 'bookshelf.png', h: 2.1 });
  upright(['decor/bookshelf_wide.png'], { file: 'bookshelf_wide.png', long: true, h: 2.1 });
  upright(['decor/wardrobe.png', 'decor/cabinet_tall.png'], { file: 'wardrobe.png', h: 2.0 });

  /**
   * Everything that stands up on this board, worked out once per render.
   *
   * Returns the cards' markup (back to front), the props' floor shadows, and
   * which tokens and props have a card so the SVG can hide their flat copies.
   * Each card and the flat thing it replaces share a `data-card` tag, which
   * is how cardFail() finds what to hand back.
   */
  function deal(state, o, grid, turned) {
    const deck = {
      cards: '',
      shadows: '',
      propCard: new Map(),
      tokenCard: new Map(),
      // Fixed and drawn boards are rooms; an outdoor palette has no walls.
      walled: INDOOR.has(grid.palette) || !!grid.image,
      turned: !!turned,
    };
    const n = (v) => Number(v).toFixed(3);
    const torches = deck.walled ? (torchPlan(grid) || []) : [];
    // Torchlight on a figure: brighter near a flame, never black. The floor
    // glow is the SVG's; this is only so a goblin in the far corner of a lit
    // room does not stand there at full daylight. Asked in the plane's frame,
    // answered in the board's, where the torches are.
    const litAt = (vx, vy) => {
      if (!torches.length) return '';
      const [fx, fy] = toBoard(vx, vy, turned);
      // A rim torch every four or five cells along both long edges puts most
      // of a 16×12 floor within reach of two of them, so the falloff is short
      // or everybody stands in the same full light.
      const sum = torches.reduce((a, t) => a + Math.max(0, 1 - Math.hypot(fx - t.x, fy - t.y) / 4.5), 0);
      return `;--lit:${(0.72 + 0.38 * Math.min(1, sum)).toFixed(2)}`;
    };
    const ui = state.ui || null;
    const targets = (ui && ui.targets && !Array.isArray(ui.targets)) ? ui.targets : {};
    const list = [];
    let seq = 0;

    // Figures. Only the living and standing, and only once the catalogue has
    // said which sheet is real: before then figureFor() is guessing, and a
    // card that guessed wrong would flash in and fall back on every board
    // drawn in the first half-second of a fight.
    for (const c of (figureIndex ? state.combatants || [] : [])) {
      if (c.x == null || c.y == null || !c.alive) continue;
      if ((c.conditions || []).some((k) => (typeof k === 'string' ? k : k.key) === 'unconscious')) continue;
      const artKey = String((o.artKey ? o.artKey(c) : '') || c.sprite_key || '');
      let spec = figureFor(c, artKey);
      // No full-body sheet (a companion with a portrait of their own, a
      // monster the catalogue has not drawn): a standee — the portrait token
      // stood up on a base — rather than a coin lying on the floor beside
      // people who are standing.
      let face = '';
      if (!spec) {
        if (!o.faceUrl) continue;
        face = String(o.faceUrl(c) || '');
        if (!face) continue;
        const size = knownSize(c.size) || 'medium';
        const fh = FIGURE_H[size];
        spec = { size, height: fh, footprint: fh >= 2 ? fh : 1, aspect: 1, file: '' };
      }
      const tag = `c${++seq}`;
      const disc = face ? (spec.footprint > 1 ? spec.footprint * 0.9 : Math.max(0.6, Math.min(1, spec.height))) : 0;
      const h = face ? disc * STANDEE_H : spec.height * CARD_SCALE;
      const w = face ? disc : h * spec.aspect;
      // Seated on the footprint the way figureHtml() seats the flat sheet.
      const dx = (spec.footprint - 1) / 2;
      const dy = (spec.footprint - 1) + CARD_FOOT;
      const lift = spec.size === 'tiny' && !face ? TINY_LIFT * CARD_SCALE : 0;
      const fp = spec.footprint;
      // The shadow is drawn inside the token's group, in the board's units
      // and relative to the token's centre; turned, the foot offset in the
      // plane's frame comes back to the board rotated, with its radii swapped.
      const rx = Math.max(0.26, w * 0.4);
      deck.tokenCard.set(c.cid, turned
        ? { tag, sx: dy - 0.04, sy: (fp - 1) - dx, rx: rx * 0.6, ry: rx }
        : { tag, sx: dx, sy: dy - 0.04, rx, ry: rx * 0.6 });
      const [ax, ay] = toView(c.x, c.y, fp, turned);
      const fx = ax + 0.5 + dx;
      const fy = ay + 0.5 + dy;
      const pct = c.max_hp ? Math.max(0, Math.min(1, c.hp / c.max_hp)) : 0;
      const shot = targets[c.cid];
      const cover = shot && Number(shot.cover) > 0
        ? `<svg class="bm-card-cover" viewBox="-0.28 -0.3 0.56 0.62" aria-hidden="true">${
          shieldHtml(0, 0, Number(shot.cover), 'bm-cover-badge')}</svg>`
        : '';
      const art = face
        ? `<span class="bm-standee-base"></span>
          <img class="bm-card-art bm-standee-face" src="${esc(face)}" alt=""
               draggable="false" onerror="window.BattleMap.cardFail(this)">`
        : `<img class="bm-card-art is-pixel" src="${esc(artUrl('figures/' + spec.file))}" alt=""
               draggable="false" onerror="window.BattleMap.cardFail(this)">`;
      list.push({ fx, fy, html: `<div class="bm-card is-figure${face ? ' is-standee' : ''} ${c.side === 'party' ? 'is-party' : 'is-foe'}"
          data-card="${tag}" data-cid="${esc(c.cid)}" data-cx="${n(fx)}" data-cy="${n(fy)}"
          data-dx="${n(dx)}" data-dy="${n(dy)}" data-fp="${fp}"
          style="--cx:${n(fx)};--cy:${n(fy)};--w:${n(w)};--h:${n(h)};--lift:${n(lift)}${litAt(fx, fy)}">
        <div class="bm-card-body">
          ${art}
          <div class="bm-card-hp"><i class="${pct > 0.5 ? 'ok' : pct > 0.25 ? 'mid' : 'low'}"
               style="width:${(pct * 100).toFixed(1)}%"></i></div>
          ${cover}
        </div></div>` });
    }

    // Props.
    for (const p of (Array.isArray(grid.props) ? grid.props : [])) {
      const up = UPRIGHT[p.src];
      if (!up) continue;
      let w = Math.max(1, Number(p.w) || 1);
      let h = Math.max(1, Number(p.h) || 1);
      let x = Number(p.x) || 0;
      let y = Number(p.y) || 0;
      let across = Number(p.rot) === 90;
      // Turned, the footprint is laid out in the plane's frame, and a run
      // that went east to west now runs away from the viewer (end cards) and
      // a north-south one now runs across (one long card).
      if (turned) [x, y, w, h, across] = [H - y - h, x, h, w, !across];
      const parts = [];
      const uh = up.h * PROP_SCALE;
      // Only a run (a `long` or `tile` piece) has a direction; a single
      // thing — a crate, a wardrobe — stands the same whichever way it was
      // turned, so it never takes the end-on branch.
      if (!up.long && !up.tile) {
        parts.push({ file: up.file, fx: x + w / 2, fy: y + h - 0.2, w: uh * 0.6, h: uh, fit: 'aspect' });
      } else if (across && up.end) {
        for (let j = 0; j < h; j++) {
          parts.push({ file: up.end, fx: x + w / 2, fy: y + j + 0.8, w: uh * 0.6, h: uh, fit: 'aspect' });
        }
      } else if (across) {
        // A run seen end-on with no end-on picture (a bench, a table): one
        // narrow card per cell, its side view squeezed to the cell — reads as
        // the near end of the thing, and depth-sorts cell by cell.
        for (let j = 0; j < h; j++) {
          parts.push({ file: up.file, fx: x + w / 2, fy: y + j + 0.8, w: 0.92, h: uh * 0.85, fit: 'squash' });
        }
      } else if (up.tile) {
        // Art of one thing (a single tipped table), so a run is a row of them
        // rather than one picture stretched three cells wide.
        for (let i = 0; i < w; i++) {
          parts.push({ file: up.file, fx: x + i + 0.5, fy: y + h - 0.2, w: uh * 0.6, h: uh, fit: 'aspect' });
        }
      } else {
        parts.push({ file: up.file, fx: x + w / 2, fy: y + h - 0.2, w: w * 1.04, h: uh, fit: 'long' });
      }
      const tag = `c${++seq}`;
      deck.propCard.set(p, tag);
      for (const c of parts) {
        const rx = c.fit === 'long' ? c.w / 2 : Math.max(0.34, Math.min(0.6, c.h * 0.32));
        const ry = c.fit === 'long' ? 0.3 : rx * 0.6;
        // The shadows are drawn in the SVG, so in the board's frame.
        const [sx, sy] = toBoard(c.fx + 0.08, c.fy - 0.1, turned);
        deck.shadows += `<ellipse class="bm-shadow" data-card="${tag}" cx="${n(sx)}" cy="${n(sy)}"
          rx="${n(turned ? ry : rx)}" ry="${n(turned ? rx : ry)}" fill="url(#bm-shadow-grad)"/>`;
        list.push({ fx: c.fx, fy: c.fy, html: `<div class="bm-card is-prop" data-card="${tag}"
            data-fit="${c.fit}" data-cap="${n(uh)}" data-cx="${n(c.fx)}" data-cy="${n(c.fy)}"
            style="--cx:${n(c.fx)};--cy:${n(c.fy)};--w:${n(c.w)};--h:${n(c.h)}${litAt(c.fx, c.fy)}">
          <div class="bm-card-body">
            <img class="bm-card-art" src="${esc(artUrl(UP + c.file))}" alt="" draggable="false"
                 onload="window.BattleMap.cardSized(this)" onerror="window.BattleMap.cardFail(this)">
          </div></div>` });
      }
    }

    // Back to front. preserve-3d sorts by depth anyway; DOM order is what
    // settles a tie, and a tie is exactly what two cards on one row are.
    list.sort((a, b) => (a.fy - b.fy) || (a.fx - b.fx));
    deck.cards = list.map((c) => c.html).join('');
    return deck;
  }

  /**
   * The walls standing on the rim, the sconces on the back one, and the lip.
   *
   * An outdoor board gets no walls, only the lip — but it does get a GHOST
   * back wall, invisible and Medium-figure tall, which fit() measures like any
   * other. That reserves headroom for a figure on the back row without fit()
   * measuring the cards themselves, which would make the board change size
   * every time somebody walked to the back of it.
   */
  function wallsHtml(grid, deck) {
    if (!deck.walled) {
      return `<div class="bm-wall is-back is-ghost"></div><div class="bm-lip is-earth"></div><div class="bm-edgefade"></div>`;
    }
    const tex = ` style="--wall-tex:url('${esc(artUrl(grid.wall || UP + 'wall_face.jpg'))}')"`;
    // `tx` is along the back wall, in the plane's frame. Turned, the back
    // wall is the board's left edge, which the generator never hangs a torch
    // on — so a lit board gets a few spaced evenly along it instead.
    const plan = torchPlan(grid) || [];
    let backs = deck.turned
      ? plan.filter((t) => t.x < 1.2).map((t) => ({ ...t, tx: H - t.y }))
      : plan.filter((t) => t.y < 1.2).map((t) => ({ ...t, tx: t.x }));
    if (deck.turned && !backs.length && plan.length) {
      const k = H >= 10 ? 3 : 2;
      backs = Array.from({ length: k }, (_, i) => ({ ...plan[i % plan.length], tx: H * (i + 1) / (k + 1) }));
    }
    const sconces = backs.map((t) => {
      const at = `--tx:${t.tx.toFixed(2)}`;
      return `<div class="bm-wall-glow" style="${at};animation-duration:${t.dur}s;animation-delay:${t.delay}s"></div>
        <img class="bm-torch" src="${esc(artUrl(UP + 'torch.png'))}" alt="" draggable="false"
             style="${at}" onerror="this.remove()">`;
    }).join('');
    return `<div class="bm-wall is-back"${tex}>${sconces}</div>
      <div class="bm-wall is-left"${tex}></div><div class="bm-wall is-right"${tex}></div>
      <div class="bm-lip"></div><div class="bm-edgefade"></div>`;
  }

  /**
   * A card's art came in: give it the width (or, for a long piece, the
   * height) its picture's proportions ask for.
   */
  function cardSized(img) {
    const card = img && img.closest('.bm-card');
    if (!card || !img.naturalWidth || !img.naturalHeight) return;
    if (card.dataset.fit === 'squash') return;  // sized to its cell on purpose
    const aspect = img.naturalWidth / img.naturalHeight;
    if (card.dataset.fit === 'long') {
      const w = parseFloat(card.style.getPropertyValue('--w')) || 1;
      const cap = parseFloat(card.dataset.cap) || 1;
      card.style.setProperty('--h', Math.min(cap, w / aspect).toFixed(3));
    } else {
      const h = parseFloat(card.style.getPropertyValue('--h')) || 1;
      card.style.setProperty('--w', (h * aspect).toFixed(3));
    }
  }

  /**
   * A card's art is missing: take the card (and its siblings, for a run of
   * end cards) away and put the flat thing back, so a board with art not yet
   * on disk looks exactly like the flat board did rather than having holes.
   */
  function cardFail(img) {
    const card = img && img.closest('.bm-card');
    const plane = card && card.closest('.bm-plane');
    if (!plane) return;
    const sel = `[data-card="${cssEscape(card.dataset.card)}"]`;
    plane.querySelectorAll(`.bm-card${sel}, .bm-shadow${sel}`).forEach((el) => el.remove());
    plane.querySelectorAll(`.has-card${sel}`).forEach((el) => {
      el.classList.remove('has-card');
      const shadow = el.querySelector('.bm-shadow');
      if (shadow) shadow.remove();
    });
  }

  /**
   * Hand the camera's view to the stylesheet, and hide the cards it is not
   * looking at.
   *
   * The plane cannot clip — overflow on it would flatten the 3D — so a card
   * whose foot is outside the viewBox would stand on thin air beside the
   * board. Hiding it is the clip, done per card.
   */
  function syncVars(root) {
    const plane = root && root.querySelector('.bm-plane');
    const svg = plane && plane.querySelector('.battlemap');
    if (!svg) return;
    const pw = parseFloat(plane.style.width);
    if (!pw) return;
    const vb = svg.viewBox && svg.viewBox.baseVal;
    const b = vb && vb.width ? vb : { x: 0, y: 0, width: W, height: H };
    // The viewBox is in the board's frame; the cards are in the plane's.
    const v = plane.closest('.is-turned')
      ? { x: H - (b.y + b.height), y: b.x, width: b.height, height: b.width }
      : b;
    plane.style.setProperty('--vbx', v.x.toFixed(4));
    plane.style.setProperty('--vby', v.y.toFixed(4));
    plane.style.setProperty('--vbw', v.width.toFixed(4));
    plane.style.setProperty('--vbh', v.height.toFixed(4));
    plane.style.setProperty('--cell', `${(pw / v.width).toFixed(3)}px`);
    for (const card of plane.querySelectorAll('.bm-card')) {
      const cx = parseFloat(card.dataset.cx);
      const cy = parseFloat(card.dataset.cy);
      card.classList.toggle('is-off',
        cx < v.x || cx > v.x + v.width || cy < v.y || cy > v.y + v.height);
    }
  }

  /**
   * Size the tilted board to fill its box.
   *
   * There is no closed form worth trusting once perspective is involved, so
   * this guesses from the orthographic projection — the floor's height times
   * cos(tilt), plus the back wall's times sin(tilt) — and then MEASURES. The
   * bounding rect of a 3D-transformed element is its projected box, so the
   * union of the plane's, the walls' and the lip's rects is exactly what is
   * on screen; the plane is rescaled by how far that misses, and once more in
   * case the perspective (which scales with the plane) moved it. Then the
   * whole thing is shifted so that union sits in the middle of the box.
   *
   * Cards are deliberately not measured (see wallsHtml), and nor is anything
   * that depends on the zoom: the walls are sized off `--cell0`, the zoom-1
   * cell, and hidden with `visibility` rather than `display` when zoomed so
   * they still have a box. So the answer depends only on the box and the
   * board, and a re-render mid-zoom lands on the same size.
   *
   * Before the scale, the ANGLE: bisected inside the view's TILT_RANGE until
   * the measured union's aspect matches the box's, so a tall panel gets a
   * shallower floor and a letterbox a steeper one, rather than bands. The
   * projected aspect barely depends on the scale (the perspective scales with
   * the board), so each probe is one apply and one measure. The answer is
   * memoised by box and board, so an ordinary redraw re-measures only to
   * centre. Whether the board should be TURNED is checked here too, and a
   * redraw asked for if the box disagrees with what was drawn.
   */
  let fitMemo = null;

  function fit(root) {
    const stage = root && root.querySelector('.bm-stage');
    const plane = stage && stage.querySelector('.bm-plane');
    if (!plane) return;
    const cw = stage.clientWidth;
    const ch = stage.clientHeight;
    if (!cw || !ch) return;
    const mode = stage.classList.contains('is-low') ? 'low' : 'tilt';
    const turned = stage.classList.contains('is-turned');
    lastBox = { w: cw, h: ch };
    if (turnFor(mode, lastBox, turned) !== turned) askReflow(root);
    else reflowRuns = 0;
    const VW = turned ? H : W;
    const VD = turned ? W : H;
    const [lo, hi] = TILT_RANGE[mode];

    const setDeg = (d) => {
      stage.style.setProperty('--tilt', `${d.toFixed(2)}deg`);
      lastDeg[`${mode}${turned ? '+t' : ''}`] = d.toFixed(2);
    };
    const guess = (d) => {
      const t = d * Math.PI / 180;
      return Math.min(cw / (VW + 1), ch / (VD * Math.cos(t) + WALL_H * Math.sin(t) + 1));
    };
    const apply = (cell, sx, sy) => {
      plane.style.width = `${(VW * cell).toFixed(2)}px`;
      plane.style.height = `${(VD * cell).toFixed(2)}px`;
      // The SVG's own box, which the turned plane rotates into place.
      plane.style.setProperty('--board-w', `${(W * cell).toFixed(2)}px`);
      plane.style.setProperty('--board-h', `${(H * cell).toFixed(2)}px`);
      plane.style.setProperty('--cell0', `${cell.toFixed(3)}px`);
      plane.style.setProperty('--sx', `${sx.toFixed(1)}px`);
      plane.style.setProperty('--sy', `${sy.toFixed(1)}px`);
      // Perspective in proportion to the board, so a small board and a big
      // one lean back by the same amount rather than the small one looking
      // like a photograph taken from across the room.
      stage.style.setProperty('--persp', `${(Math.max(W, H) * cell * 2.6).toFixed(0)}px`);
    };
    const union = () => {
      let l = Infinity; let t = Infinity; let r = -Infinity; let b = -Infinity;
      for (const el of [plane, ...plane.querySelectorAll('.bm-wall, .bm-lip')]) {
        const q = el.getBoundingClientRect();
        if (!q.width && !q.height) continue;
        l = Math.min(l, q.left); t = Math.min(t, q.top);
        r = Math.max(r, q.right); b = Math.max(b, q.bottom);
      }
      return r > l ? { l, t, w: r - l, h: b - t } : null;
    };

    // Steeper is shorter, so the aspect rises with the angle.
    const aspectAt = (d) => {
      setDeg(d);
      apply(guess(d), 0, 0);
      const u = union();
      return u ? u.w / u.h : 0;
    };
    const lean = () => {
      const want = cw / ch;
      if (aspectAt(lo) >= want) return lo;
      if (aspectAt(hi) <= want) return hi;
      let a = lo;
      let b = hi;
      for (let i = 0; i < 6; i++) {
        const m = (a + b) / 2;
        if (aspectAt(m) < want) a = m; else b = m;
      }
      return (a + b) / 2;
    };
    const size = (deg) => {
      let c = guess(deg);
      for (let i = 0; i < 3; i++) {
        apply(c, 0, 0);
        const u = union();
        if (!u) break;
        const k = Math.min(cw / u.w, ch / u.h) * 0.97;
        if (Math.abs(k - 1) < 0.01) break;
        c *= k;
      }
      return c;
    };

    const memoKey = [cw, ch, W, H, mode, turned, !!plane.querySelector('.bm-wall.is-ghost')].join('|');
    if (!fitMemo || fitMemo.key !== memoKey) {
      const deg = lean();
      setDeg(deg);
      fitMemo = { key: memoKey, deg, cell: size(deg) };
    }
    setDeg(fitMemo.deg);
    const cell = fitMemo.cell;
    apply(cell, 0, 0);
    const u = union();
    const box = stage.getBoundingClientRect();
    const sx = u ? (box.left + box.width / 2) - (u.l + u.w / 2) : 0;
    const sy = u ? (box.top + box.height / 2) - (u.t + u.h / 2) : 0;
    apply(cell, sx, sy);
    syncVars(root);
  }

  /**
   * Refit when the board's box changes shape — a window resized, a side
   * panel collapsed. One observer for the module, moved to each new board as
   * it is drawn, so a render is never an observer leaked.
   */
  let resizeObs = null;
  let resizeFrame = 0;
  function observe(root) {
    if (resizeObs) resizeObs.disconnect();
    if (resizeFrame) cancelAnimationFrame(resizeFrame);
    resizeObs = null;
    resizeFrame = 0;
    if (!root || !root.querySelector('.bm-stage') || typeof ResizeObserver !== 'function') return;
    resizeObs = new ResizeObserver(() => {
      if (resizeFrame) return;
      resizeFrame = requestAnimationFrame(() => { resizeFrame = 0; fit(root); });
    });
    resizeObs.observe(root);
  }

  /**
   * Ask the caller to draw the board again, because the box now wants it
   * turned the other way. One frame later, so a burst of fits asks once; and
   * checked again then against whatever board is on screen by that time.
   * `reflowRuns` is the loop guard: it is cleared by any fit that is content
   * with what it was given, and a redraw that somehow keeps disagreeing is
   * left alone after two tries rather than redrawn forever.
   */
  let reflow = null;
  let reflowFrame = 0;
  let reflowRuns = 0;
  function askReflow(root) {
    if (reflowFrame) cancelAnimationFrame(reflowFrame);
    reflowFrame = requestAnimationFrame(() => {
      reflowFrame = 0;
      const stage = root.isConnected && root.querySelector('.bm-stage');
      if (!stage || !reflow || reflowRuns >= 2) return;
      const turned = stage.classList.contains('is-turned');
      const mode = stage.classList.contains('is-low') ? 'low' : 'tilt';
      if (turnFor(mode, lastBox, turned) === turned) return;
      reflowRuns++;
      reflow();
    });
  }

  /** The cards standing for one combatant (normally one; none for the flat). */
  function cardsFor(root, cid) {
    return root ? root.querySelectorAll(`.bm-card[data-cid="${cssEscape(cid)}"]`) : [];
  }

  /**
   * Which token the pointer is on, told to its card: a card is pointer-events
   * none, so it cannot hover itself, and the cover badge it carries is shown
   * on hover the way the flat token's is.
   */
  function hoverCard(root, cid) {
    if (!root || !root.querySelector('.bm-cards')) return;
    for (const el of root.querySelectorAll('.bm-card.is-hovered')) {
      if (el.dataset.cid !== cid) el.classList.remove('is-hovered');
    }
    if (cid) for (const el of cardsFor(root, cid)) el.classList.add('is-hovered');
  }

  // =========================================================================
  // The cheap updates: things that change on hover, without a full redraw
  // =========================================================================

  /**
   * Draw the walk the server would take to a cell.
   *
   * Walks `ui.reach[key].from` backwards — the predecessor chain the server's
   * own search recorded on the way. That is why hovering a cell costs no
   * request and cannot draw a route the server would price differently: it is
   * not a route the client worked out.
   */
  function paintPath(root, state, destKey) {
    const layer = root && root.querySelector('.bm-path');
    if (!layer) return;
    const reach = state.ui && state.ui.reach;
    if (!reach || !destKey || !reach[destKey] || !reach[destKey].end || !reach[destKey].cost) {
      layer.innerHTML = '';
      return;
    }

    const points = [];
    let at = destKey;
    let guard = 0;
    while (at && guard++ < W * H + 2) {
      const [x, y] = parseKey(at);
      points.unshift(`${x + 0.5},${y + 0.5}`);
      at = reach[at].from;
    }

    const entry = reach[destKey];
    const [dx, dy] = parseKey(destKey);
    layer.innerHTML = `
      <polyline class="bm-route ${entry.provokes ? 'is-provokes' : ''}" points="${points.join(' ')}"/>
      <circle class="bm-route-end ${entry.provokes ? 'is-provokes' : ''}" cx="${dx + 0.5}" cy="${dy + 0.5}" r="0.3"/>
      <text class="bm-route-cost" x="${dx + 0.5}" y="${dy + 0.5}" dy="0.12">${entry.cost}</text>`;
  }

  /** The footprint of a spell, painted from the server's own `aoe` event. */
  function paintAoe(root, cells) {
    const layer = root && root.querySelector('.bm-aoe');
    if (!layer) return;
    layer.innerHTML = (cells || []).map((k) => {
      const [x, y] = parseKey(k);
      return `<rect class="bm-blast" x="${x}" y="${y}" width="1" height="1"/>`;
    }).join('');
  }

  /**
   * The ghost under the cursor while a spell is being placed.
   *
   * A square for a radius or a cube and a line for anything aimed, which is a
   * drawing rather than a rule — the server decides which cells the template
   * actually covers and says so in the `aoe` event when the spell goes off.
   * Sketching the real shape here would be the mirrored-geometry mistake this
   * file exists to avoid, for the sake of a preview that is one click away
   * from being replaced by the truth.
   */
  function paintAim(root, shape, ft, from, cell) {
    const layer = root && root.querySelector('.bm-aoe');
    if (!layer) return;
    if (!cell) {
      layer.innerHTML = '';
      return;
    }
    const [x, y] = cell;
    const cells = Math.max(1, Math.ceil((ft || 5) / 5));

    if (shape === 'radius' || shape === 'sphere' || shape === 'cube') {
      const r = shape === 'cube' ? Math.floor((cells - 1) / 2) : cells;
      layer.innerHTML = `<rect class="bm-aim" x="${x - r}" y="${y - r}"
        width="${r * 2 + 1}" height="${r * 2 + 1}"/>`;
      return;
    }
    layer.innerHTML = `<line class="bm-aim-line" x1="${from[0] + 0.5}" y1="${from[1] + 0.5}"
      x2="${x + 0.5}" y2="${y + 0.5}"/>
      <rect class="bm-aim" x="${x}" y="${y}" width="1" height="1"/>`;
  }

  // =========================================================================
  // Things that happen for a moment
  //
  // Both of these are drawn INSIDE the svg, in cell units, for the reason the
  // header gives: the viewBox is the game space, so a float over the archer at
  // (3,7) is `translate(3.5 7.5)` and there is no conversion to get wrong. An
  // HTML overlay would have to undo the letterboxing that preserveAspectRatio
  // adds — the board is 4:3 and its column is not — and would drift the moment
  // the window changed shape.
  //
  // One consequence worth stating: a CSS length on an SVG child is a USER
  // unit, so `translateY(-1px)` in the stylesheet rises exactly one cell. The
  // numbers in the keyframes look wrong and are right.
  // =========================================================================

  /**
   * Numbers over somebody's head, gone in about a second.
   *
   * Removed on a timer rather than on animationend: an animation that never
   * starts — a hidden tab, reduced motion, a board redrawn underneath it —
   * never ends either, and a float that stayed forever would be worse than one
   * that vanished early.
   */
  function floatText(root, x, y, text, cls) {
    // In the diorama a number painted on the SVG would be painted on the
    // FLOOR, lying back at fifty degrees. So it is a little card of its own,
    // standing at the cell like a figure does and rising off it.
    const cards = root && root.querySelector('.bm-cards');
    if (cards) {
      const f = document.createElement('div');
      f.className = `bm-cfloat ${cls || ''}`;
      const [ax, ay] = toView(x, y, 1, !!cards.closest('.is-turned'));
      f.style.setProperty('--cx', (ax + 0.5).toFixed(3));
      f.style.setProperty('--cy', (ay + 0.5 + CARD_FOOT).toFixed(3));
      const span = document.createElement('span');
      span.textContent = text;
      f.appendChild(span);
      cards.appendChild(f);
      setTimeout(() => f.remove(), 1200);
      return;
    }
    const layer = root && root.querySelector('.bm-floats');
    if (!layer) return;
    const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    g.setAttribute('class', `bm-float ${cls || ''}`);
    g.setAttribute('transform', `translate(${x + 0.5} ${y + 0.5})`);
    g.innerHTML = `<text y="-0.55">${esc(text)}</text>`;
    layer.appendChild(g);
    setTimeout(() => g.remove(), 1200);
  }

  /**
   * A bolt crossing the board, for an attack made at a distance.
   *
   * Deliberately small: a dart and a fading trail, about a fifth of a second.
   * It exists so that a shot from across the room has somewhere to happen —
   * before this, a bow and a sword produced identical output on the board and
   * only the log said which. It is not a spell effect and should not grow into
   * one.
   */
  function shoot(root, from, to, cls) {
    const layer = root && root.querySelector('.bm-shots');
    if (!layer || !from || !to) return 0;
    const [x1, y1] = [from[0] + 0.5, from[1] + 0.5];
    const [x2, y2] = [to[0] + 0.5, to[1] + 0.5];

    const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    g.setAttribute('class', `bm-shot ${cls || ''}`);
    g.innerHTML =
      `<line class="bm-trail" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"/>
       <circle class="bm-bolt" cx="${x1}" cy="${y1}" r="0.11"
               style="--dx:${(x2 - x1).toFixed(3)}px; --dy:${(y2 - y1).toFixed(3)}px"/>`;
    layer.appendChild(g);
    setTimeout(() => g.remove(), 400);
    return SHOT_MS;
  }

  /** How long a bolt is in the air. Matched by `bm-fly` in the stylesheet. */
  const SHOT_MS = 220;

  /**
   * A blow landing, on the board: the struck figure flashes and flinches.
   *
   * `kind` is 'hit', 'crit' or 'heal'. The flinch is on the token's inner
   * `.bm-body`, because the outer group's transform is its position and a
   * CSS animation there would yank it to the corner of the board. The board
   * itself jolts a couple of screen pixels on a hit and about five on a crit
   * (the <svg> element is moved, not its viewBox, so the camera is untouched).
   */
  function hitToken(root, cid, kind) {
    const g = root && root.querySelector(`.bm-token[data-cid="${cssEscape(cid)}"]`);
    if (!g) return;
    const cls = kind === 'crit' ? 'is-crit' : kind === 'heal' ? 'is-healed' : 'is-struck';
    // The token and, in the diorama, its card: the same classes on both, and
    // the stylesheet decides which of them has anything to animate.
    for (const el of [g, ...cardsFor(root, cid)]) {
      el.classList.remove('is-struck', 'is-crit', 'is-healed');
      // Reflow so a second hit in the same beat restarts the animation.
      void el.getBoundingClientRect();
      el.classList.add(cls);
      setTimeout(() => el.classList.remove(cls), 460);
    }
    if (kind === 'crit' || kind === 'hit') {
      // Tilted, the jolt moves the stage: the svg is inside the 3D chain, and
      // an animated transform on it would be a second transform fighting the
      // plane's. The stage holds the perspective and is outside the chain.
      const board = root.querySelector('.bm-stage') || root.querySelector('.battlemap');
      if (board) {
        const jolt = kind === 'crit' ? 'is-jolt-big' : 'is-jolt';
        board.classList.remove('is-jolt', 'is-jolt-big');
        void board.getBoundingClientRect();
        board.classList.add(jolt);
        setTimeout(() => board.classList.remove(jolt), 320);
      }
    }
  }

  /** Mark a token as walking, for the little step bob while it slides. */
  function walking(root, cid, on) {
    const g = root && root.querySelector(`.bm-token[data-cid="${cssEscape(cid)}"]`);
    if (g) g.classList.toggle('is-walking', !!on);
    // On the card this also turns its slide on: a card only transitions
    // `left`/`top` while walking, so a pan or a zoom (which move every card
    // through the same properties) never lags behind the floor.
    for (const el of cardsFor(root, cid)) el.classList.toggle('is-walking', !!on);
  }

  /** Slide a token to where it ended up, for the move animation. */
  function moveToken(root, cid, x, y) {
    const g = root && root.querySelector(`.bm-token[data-cid="${cssEscape(cid)}"]`);
    if (!g) return;
    g.setAttribute('transform', `translate(${x + 0.5} ${y + 0.5})`);
    const turned = !!root.querySelector('.bm-stage.is-turned');
    for (const card of cardsFor(root, cid)) {
      const [ax, ay] = toView(x, y, parseFloat(card.dataset.fp) || 1, turned);
      const cx = ax + 0.5 + (parseFloat(card.dataset.dx) || 0);
      const cy = ay + 0.5 + (parseFloat(card.dataset.dy) || 0);
      card.dataset.cx = cx.toFixed(3);
      card.dataset.cy = cy.toFixed(3);
      card.style.setProperty('--cx', cx.toFixed(3));
      card.style.setProperty('--cy', cy.toFixed(3));
    }
    if (root.querySelector('.bm-cards')) syncVars(root);
  }

  function cssEscape(s) {
    return String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  // =========================================================================
  // Input
  // =========================================================================

  /**
   * Wire one drawn board up.
   *
   * Bound directly rather than delegated at the document, matching what
   * ui-combat.js already does for its cards — game.js:751 deliberately scopes
   * its own delegated handler to the location screen so that the combat board
   * keeps its listeners to itself.
   *
   * The keydown handler is not optional: an SVG element with a tabindex takes
   * focus but does not fire a click on Enter, which is the same trap game.js
   * carries a handler for on the region chart.
   */
  // =========================================================================
  // The camera
  // =========================================================================

  const ZOOM_MIN = 1;      // the whole board, fitted
  const ZOOM_MAX = 3.5;    // about four cells across, which is a melee
  const ZOOM_STEP = 1.3;

  /**
   * The one camera, and the view it is looking through.
   *
   * A module-level pair rather than a map keyed by the board's container,
   * which was the first attempt and does not work: `.cbt-map` is part of the
   * combat screen's innerHTML and is itself replaced on every render, so the
   * key was as short-lived as the SVG it was standing in for. There is only
   * ever one battlefield on screen — the state is simply the module's.
   *
   * `forget()` is what makes a new fight open fitted; without it the second
   * encounter of the evening would begin zoomed into a corner of a board the
   * player has not seen yet.
   */
  let boardView = null;   // {z, cx, cy} across redraws
  let boardCam = null;    // the live SvgView for the SVG on screen

  /**
   * Is the space bar down, and is it ours?
   *
   * Held at module scope rather than per board because it is a fact about the
   * keyboard, and because a listener per redraw would be a listener leaked per
   * redraw. It is deliberately NOT taken when the focus is somewhere space
   * already means something: a button (which space presses), a checkbox — "My
   * dice" is one — a text field, or a focused cell on the board, where space is
   * the keyboard's own way of moving there. Stealing it in any of those would
   * break a control that works today to add one that is a convenience.
   */
  let spaceHeld = false;

  function spaceIsOurs(target) {
    const el = target || document.activeElement;
    // `document` itself has no closest(), and is what a keydown targets when
    // nothing at all is focused.
    if (!el || el === document.body || typeof el.closest !== 'function') return true;
    return !el.closest(
      'button, input, select, textarea, a[href], [contenteditable], [data-bm-cell], .bm-token'
    );
  }

  document.addEventListener('keydown', (e) => {
    if (e.key !== ' ' && e.code !== 'Space') return;
    if (e.repeat || !spaceIsOurs(e.target)) return;
    spaceHeld = true;
    document.body.classList.add('bm-pan-ready');
    // The shell does not scroll, but a bench or a phone might.
    e.preventDefault();
  });
  const dropSpace = () => {
    spaceHeld = false;
    document.body.classList.remove('bm-pan-ready');
  };
  document.addEventListener('keyup', (e) => {
    if (e.key === ' ' || e.code === 'Space') dropSpace();
  });
  // A window that loses focus mid-hold never sees the keyup.
  window.addEventListener('blur', dropSpace);

  /**
   * Give the board a camera, and give it back the one it had.
   *
   * Called after every render, because every render is a new SVG. The view is
   * saved against the container, so zoom and position survive a swing, a walk
   * and a monster's turn.
   */
  function controls(root) {
    const svg = root && root.querySelector('.battlemap');
    if (!svg) return null;
    if (!window.SvgView) {
      // No camera, but a tilted board still has to be sized.
      fit(root);
      observe(root);
      return null;
    }

    boardCam = window.SvgView(svg, {
      base: { x: 0, y: 0, w: W, h: H },
      zoomMin: ZOOM_MIN,
      zoomMax: ZOOM_MAX,
      zoomStep: ZOOM_STEP,
      start: boardView,
      // A turned board's SVG is rotated a quarter on screen, so a drag and a
      // zoom anchor have to be rotated back into its units.
      turned: () => !!svg.closest('.is-turned'),
      // Drag the ground with any button and the camera follows. A bare
      // left-click is still a move order — SvgView ignores movement under its
      // few pixels of slop, and the click handler asks `consumePan()` so a pan
      // that ends over a cell never also counts as having clicked it.
      //
      // Dragging your own figure is the other gesture, and it must not be this
      // one. canPan refuses the acting party token so the handler below can
      // draw the server's path instead of sliding the board. Space or the
      // middle button still pans, including off that token, for anyone who
      // wants to look around with no chance of ordering a step.
      canPan: (e) => {
        if (e.button === 1 || spaceHeld) return true;
        if (e.button !== 0) return false;
        if (actorTokenEl(e.target) && moveDragAllowed()) return false;
        return true;
      },
      onApply: (v) => {
        boardView = { z: v.z, cx: v.cx, cy: v.cy };
        root.classList.toggle('is-zoomed', v.z > ZOOM_MIN * 1.001);
        // The cards follow the camera through the stylesheet (see syncVars).
        // A no-op on a flat board, and before fit() has sized the plane.
        syncVars(root);
      },
    });
    // Every render is a new plane, so every render is fitted — and the
    // observer moves to the new box (or is dropped, on a flat board).
    fit(root);
    observe(root);
    return boardCam;
  }

  /** The camera the board on screen is looking through. */
  function camera() {
    return boardCam;
  }

  /** Drop the camera, so the next fight opens on the whole board. */
  function forget() {
    boardView = null;
    boardCam = null;
    observe(null);
  }

  /**
   * The acting party token under the pointer, if the press is on one.
   *
   * The figure itself does not take the hit — a 3×3 sheet would cover the
   * cells beside it — so this is the ring, which is the click target the
   * board already had.
   */
  function actorTokenEl(node) {
    if (!node || typeof node.closest !== 'function') return null;
    return node.closest('.bm-token.is-actor.is-party:not(.is-dead)');
  }

  /** Whether a drag on that token is a move. Spell placement leaves the camera in charge. */
  let moveDragAllowed = () => true;

  /** A drag-move swallows the click it ends on, across the redraw that follows. */
  let suppressClick = false;

  function bind(root, handlers) {
    const svg = root.querySelector('.battlemap');
    if (!svg) return;
    const h = handlers || {};
    moveDragAllowed = () => !h.canDrag || h.canDrag();
    // How fit() gets the board redrawn when the box wants it turned.
    reflow = h.onReflow || null;

    const cellOf = (ev) => {
      const el = ev.target.closest('[data-bm-cell]');
      return el ? parseKey(el.dataset.bmCell) : null;
    };
    // The cell under the pointer, including when a token is what the hit test
    // names. elementsFromPoint walks through the ring to the square beneath.
    const cellAt = (ev) => {
      const stack = document.elementsFromPoint(ev.clientX, ev.clientY);
      for (const el of stack) {
        if (!el || typeof el.closest !== 'function') continue;
        const hit = el.closest('[data-bm-cell]');
        if (hit && svg.contains(hit)) return parseKey(hit.dataset.bmCell);
      }
      return null;
    };
    // The dead are not tokens as far as a click is concerned. The handler
    // below asks this before it asks cellOf() and returns on the first answer,
    // so anything this matches is a square you cannot walk into. The corpse is
    // also `pointer-events: none` in the stylesheet — this is the same rule
    // said where the precedence actually lives, so restyling cannot bring the
    // bug back.
    const tokenOf = (ev) => {
      const el = ev.target.closest('.bm-token[data-cid]:not(.is-dead)');
      return el ? el.dataset.cid : null;
    };

    // A press on your own figure, dragged, previews the walk and posts it on
    // release. The route is paintPath — ui.reach, not a pathfinder. Under the
    // slop it is still a click, and a click on a cell is still a move.
    let drag = null;
    svg.addEventListener('pointerdown', (ev) => {
      if (ev.button !== 0 || spaceHeld || !moveDragAllowed()) return;
      if (!actorTokenEl(ev.target)) return;
      drag = { pointerId: ev.pointerId, x: ev.clientX, y: ev.clientY, moved: false };
    });
    svg.addEventListener('pointermove', (ev) => {
      if (!drag || ev.pointerId !== drag.pointerId) return;
      if (!drag.moved) {
        if (Math.hypot(ev.clientX - drag.x, ev.clientY - drag.y) < 4) return;
        drag.moved = true;
        try { svg.setPointerCapture(ev.pointerId); } catch (_) { /* not ours */ }
      }
      ev.preventDefault();
      if (h.onHover) h.onHover(null, cellAt(ev));
    });
    const endDrag = (ev) => {
      if (!drag || !ev || ev.pointerId !== drag.pointerId) return;
      const was = drag;
      drag = null;
      if (!was.moved) {
        // A press that began on the acting figure's card handed the pointer
        // to the svg (see the card listeners below), so no click reaches the
        // card: a press-and-release there still selects, here.
        if (was.cardCid) {
          try { svg.releasePointerCapture(was.pointerId); } catch (_) { /* already gone */ }
          if (h.onToken) h.onToken(was.cardCid);
        }
        return;
      }
      suppressClick = true;
      setTimeout(() => { suppressClick = false; }, 0);
      try { svg.releasePointerCapture(was.pointerId); } catch (_) { /* already gone */ }
      const cell = cellAt(ev);
      if (cell && h.onCell && moveDragAllowed()) h.onCell(cell[0], cell[1]);
      if (h.onHover) h.onHover(null, null);
    };
    svg.addEventListener('pointerup', endDrag);
    svg.addEventListener('pointercancel', (ev) => {
      if (!drag || ev.pointerId !== drag.pointerId) return;
      const was = drag.moved;
      drag = null;
      if (was) {
        suppressClick = true;
        setTimeout(() => { suppressClick = false; }, 0);
      }
      if (h.onHover) h.onHover(null, null);
    });

    svg.addEventListener('click', (ev) => {
      // A drag that happened to end over a cell is not a click on it. The view
      // is asked rather than told, and asking clears the flag, so one pan
      // swallows exactly one click. A figure-drag does the same with its own flag.
      if (suppressClick) { suppressClick = false; return; }
      if ((boardCam && boardCam.consumePan()) || spaceHeld) return;
      const cid = tokenOf(ev);
      if (cid) {
        if (h.onToken) h.onToken(cid);
        return;
      }
      const cell = cellOf(ev);
      if (cell && h.onCell) h.onCell(cell[0], cell[1]);
    });

    svg.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Enter' && ev.key !== ' ') return;
      const cid = tokenOf(ev);
      const cell = cellOf(ev);
      if (!cid && !cell) return;
      ev.preventDefault();
      if (cid) {
        if (h.onToken) h.onToken(cid);
      } else if (h.onCell) {
        h.onCell(cell[0], cell[1]);
      }
    });

    let litCover = null;
    const lightCover = (cell) => {
      const k = cell ? key(cell[0], cell[1]) : null;
      if (k === litCover) return;
      if (litCover) {
        const was = svg.querySelector(`[data-cover-at="${litCover}"]`);
        if (was) was.classList.remove('is-on');
      }
      litCover = k;
      const now = k && svg.querySelector(`[data-cover-at="${k}"]`);
      if (now) now.classList.add('is-on');
    };
    svg.addEventListener('mousemove', (ev) => {
      const cid = tokenOf(ev);
      const cell = cellOf(ev);
      lightCover(cid ? null : cell);
      hoverCard(root, cid);
      if (h.onHover) h.onHover(cid, cell);
    });
    svg.addEventListener('mouseleave', () => {
      lightCover(null);
      hoverCard(root, null);
      if (drag && drag.moved) return;
      if (h.onHover) h.onHover(null, null);
    });
    svg.addEventListener('focusin', (ev) => {
      lightCover(tokenOf(ev) ? null : cellOf(ev));
      if (h.onHover) h.onHover(tokenOf(ev), cellOf(ev));
    });

    // A standing figure two cells tall is what the eye aims at, so its picture
    // is a click target for its token (the stylesheet turns pointer events
    // back on for `.bm-card.is-figure .bm-card-art` only). The cost is that the
    // square behind a body is clicked past the body's feet, the same trade the
    // flat board's ring makes on a smaller scale. Props stay unclickable.
    const cards = root.querySelector('.bm-cards');
    if (cards) {
      const cardCid = (ev) => {
        const el = ev.target.closest && ev.target.closest('.bm-card.is-figure[data-cid]');
        return el ? el.dataset.cid : null;
      };
      // Your own figure's body is a drag handle too, as its ring is: the press
      // is handed to the svg with pointer capture, so the drag machinery
      // above runs unchanged and the drop is read off the cells as before.
      cards.addEventListener('pointerdown', (ev) => {
        if (ev.button !== 0 || spaceHeld || !moveDragAllowed()) return;
        const cid = cardCid(ev);
        const tok = cid && svg.querySelector('.bm-token.is-actor.is-party:not(.is-dead)');
        if (!tok || tok.dataset.cid !== cid) return;
        ev.preventDefault();
        drag = { pointerId: ev.pointerId, x: ev.clientX, y: ev.clientY, moved: false, cardCid: cid };
        try { svg.setPointerCapture(ev.pointerId); } catch (_) { /* not ours */ }
      });
      cards.addEventListener('click', (ev) => {
        if (suppressClick) { suppressClick = false; return; }
        if ((boardCam && boardCam.consumePan()) || spaceHeld) return;
        const cid = cardCid(ev);
        if (cid && h.onToken) h.onToken(cid);
      });
      cards.addEventListener('mousemove', (ev) => {
        const cid = cardCid(ev);
        lightCover(null);
        hoverCard(root, cid);
        if (h.onHover) h.onHover(cid, null);
      });
      cards.addEventListener('mouseleave', () => {
        hoverCard(root, null);
        if (h.onHover) h.onHover(null, null);
      });
    }
  }

  window.BattleMap = {
    html, bind, controls, camera, forget, whenFigures, figureUrl,
    paintPath, paintAoe, paintAim, moveToken, floatText, shoot, hitToken, walking,
    viewMode, setViewMode, nextViewMode, fit, cardSized, cardFail,
    W, H, SHOT_MS,
  };
})();
