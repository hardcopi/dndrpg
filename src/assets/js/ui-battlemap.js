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
    'o': ['is-low', 'low cover'],
    'n': ['is-tall', 'tall cover — blocks sight'],
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
  function html(state, opts) {
    const grid = state.grid || {};
    const terrain = grid.terrain || [];
    sizeFrom(terrain);
    const ui = state.ui || null;
    const o = opts || {};

    const pid = ++patternSeq;
    const floorId = `bm-fl-${pid}`;
    const wallId = `bm-wl-${pid}`;
    const waterId = `bm-wt-${pid}`;

    return `<svg class="battlemap" viewBox="0 0 ${W} ${H}"
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
        <pattern id="${waterId}" width="1" height="1" patternUnits="userSpaceOnUse">
          <image href="${esc(artUrl('tiles/water.png'))}" width="1" height="1"
                 preserveAspectRatio="xMidYMid slice"/></pattern>
      </defs>
      <rect class="bm-floor" x="0" y="0" width="${W}" height="${H}"
            ${grid.floor ? `style="fill:url(#${floorId})"` : ''}/>
      ${grid.image ? `<image class="bm-art" href="${esc(artUrl(grid.image))}"
            x="0" y="0" width="${W}" height="${H}" preserveAspectRatio="none"/>` : ''}
      <g class="bm-terrain${grid.image ? ' is-drawn' : ''}">${
        terrainHtml(terrain, { wallId, waterId, hasWall: !grid.image && !!grid.wall })
      }</g>
      <g class="bm-props">${propsHtml(grid)}</g>
      <g class="bm-zones">${zonesHtml(grid, state)}</g>
      <g class="bm-reach">${reachHtml(ui)}</g>
      <g class="bm-threat">${threatHtml(ui)}</g>
      <g class="bm-aoe"></g>
      <g class="bm-path"></g>
      <g class="bm-cells">${cellsHtml(terrain)}</g>
      <g class="bm-grid">${gridHtml()}
        <rect class="bm-edge" x="0" y="0" width="${W}" height="${H}"/></g>
      <g class="bm-tokens">${tokensHtml(state, o)}</g>
      <g class="bm-shots"></g>
      <g class="bm-floats"></g>
    </svg>`;
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
        out += `<rect class="bm-cell ${spec[0]}" x="${x}" y="${y}" width="1" height="1"/>`;
      }
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
  function propsHtml(grid) {
    const list = Array.isArray(grid.props) ? grid.props.slice() : [];
    list.sort((a, b) => (a.y - b.y) || (a.x - b.x));
    return list.map((p) => {
      const w = Math.max(1, Number(p.w) || 1);
      const h = Math.max(1, Number(p.h) || 1);
      const x = Number(p.x) || 0;
      const y = Number(p.y) || 0;
      const stand = p.stand ? Math.min(1.1, 0.55 + h * 0.2) : 0;
      const pad = 0.06;
      return `<image class="bm-prop" href="${esc(artUrl(p.src))}"
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

  function tokensHtml(state, o) {
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

      const face = esc(o.faceUrl(c));
      const stamped = !!c.token;
      const art = stamped
        ? `<image class="bm-face is-token" href="${face}" x="-0.46" y="-0.46"
             width="0.92" height="0.92" preserveAspectRatio="xMidYMid meet"
             onerror="this.remove()"/>`
        : `<image class="bm-face" href="${face}" x="-0.34" y="-0.34"
             width="0.68" height="0.68" clip-path="url(#bm-round)"
             preserveAspectRatio="xMidYMid slice"
             onerror="this.remove()"/>`;

      return `<g class="${cls}${stamped ? ' has-token' : ''}" transform="translate(${c.x + 0.5} ${c.y + 0.5})"
          data-cid="${esc(c.cid)}" ${dead ? '' : 'role="button" tabindex="0"'}>
        <title>${esc(c.name)} — HP ${esc(c.hp)}/${esc(c.max_hp)}, AC ${esc(c.ac)}${esc(note)}</title>
        <circle class="bm-hit-token" r="0.48"/>
        <circle class="bm-turn" r="0.46"/>
        <circle class="bm-ring" r="${stamped ? '0.47' : '0.40'}" ${stamped ? 'fill="none"' : ''}/>
        ${art}
        <rect class="bm-hp-back" x="-0.36" y="0.38" width="0.72" height="0.12" rx="0.06"/>
        <rect class="bm-hp ${pct > 0.5 ? 'ok' : pct > 0.25 ? 'mid' : 'low'}"
              x="-0.36" y="0.38" width="${(0.72 * pct).toFixed(3)}" height="0.12" rx="0.06"/>
      </g>`;
    }).join('');
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

  /** Slide a token to where it ended up, for the move animation. */
  function moveToken(root, cid, x, y) {
    const g = root && root.querySelector(`.bm-token[data-cid="${cssEscape(cid)}"]`);
    if (!g) return;
    g.setAttribute('transform', `translate(${x + 0.5} ${y + 0.5})`);
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
    if (!svg || !window.SvgView) return null;

    boardCam = window.SvgView(svg, {
      base: { x: 0, y: 0, w: W, h: H },
      zoomMin: ZOOM_MIN,
      zoomMax: ZOOM_MAX,
      zoomStep: ZOOM_STEP,
      start: boardView,
      // Drag with any button. A bare left-click is still a move order and
      // still only a move order — what separates the two is distance, not which
      // finger you used: SvgView ignores movement under its few pixels of slop,
      // and the click handler below asks `consumePan()` so a drag that ends over
      // a cell never also counts as having clicked it.
      //
      // This used to demand Space or the middle button, on the reasoning that a
      // board where clicking the ground sometimes walks you and sometimes
      // slides the camera is a board you cannot trust. The reasoning was right
      // and the remedy was wrong: the slop already tells a click from a drag,
      // and what it actually bought was a zoomed-in board you could not move at
      // all unless you knew about a keypress nothing on screen mentions. The
      // scene chart has worked this way the whole time.
      //
      // Space still pans, and still swallows the click while it is held, for
      // anyone who wants to look around with no chance of ordering anything.
      canPan: (e) => e.button === 0 || e.button === 1,
      onApply: (v) => {
        boardView = { z: v.z, cx: v.cx, cy: v.cy };
        root.classList.toggle('is-zoomed', v.z > ZOOM_MIN * 1.001);
      },
    });
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
  }

  function bind(root, handlers) {
    const svg = root.querySelector('.battlemap');
    if (!svg) return;
    const h = handlers || {};

    const cellOf = (ev) => {
      const el = ev.target.closest('[data-bm-cell]');
      return el ? parseKey(el.dataset.bmCell) : null;
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

    svg.addEventListener('click', (ev) => {
      // A drag that happened to end over a cell is not a click on it. The view
      // is asked rather than told, and asking clears the flag, so one pan
      // swallows exactly one click.
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

    svg.addEventListener('mousemove', (ev) => {
      const cid = tokenOf(ev);
      const cell = cellOf(ev);
      if (h.onHover) h.onHover(cid, cell);
    });
    svg.addEventListener('mouseleave', () => {
      if (h.onHover) h.onHover(null, null);
    });
    svg.addEventListener('focusin', (ev) => {
      if (h.onHover) h.onHover(tokenOf(ev), cellOf(ev));
    });
  }

  window.BattleMap = {
    html, bind, controls, camera, forget,
    paintPath, paintAoe, paintAim, moveToken, floatText, shoot,
    W, H, SHOT_MS,
  };
})();
