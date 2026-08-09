/**
 * The region chart.
 *
 * Draws one region as an inline SVG over an optional painted map: a named
 * place per location, a line per way between them, and a caption for each
 * road out of the region. Clicking a name travels there — the server routes
 * the party over however many hops it takes.
 *
 * Where you can go is shown by highlighting the PLACE NAMES, not by icons on
 * the map, and clicking one is how you travel.
 *
 * The one thing drawn as a mark rather than as text is the party itself: their
 * faces, piled at the place they are standing. That is not the rule bending —
 * the rule is about PLACES, and a token that says "a tavern is here" competes
 * with the painting that already says so. A face says who, which the painting
 * cannot, and it is the one thing on the chart that moves.
 *
 * In an authored region a name is something the party has learned. Somewhere
 * they have not been is therefore drawn without one: a place one step away — a
 * direct exit from this scene — is an empty ring, and anywhere further off
 * shows nothing but the road running toward it. Walking in names it.
 *
 * A GENERATED DUNGEON FLOOR IS FOGGED, and by the same rule. It used to name
 * every room, on the argument that its plan was drawn anyway so withholding the
 * labels only left a level of blank boxes — which was true while the whole
 * floor was drawn. It is not: the plan now ships `seen` and `glimpsed` per room
 * and per passage (DelveEngine::fog), a shape with neither is not drawn at all,
 * and a name follows the same `visited` flag every authored region uses. See
 * `floorplan()` and `node()`.
 *
 * The drawing space is a fixed 100x75 viewBox and locations carry their
 * position as percentages, so the chart scales to any width without the
 * client needing to know a single pixel dimension.
 */
(function () {
  'use strict';

  const esc = (s) => window.Game.esc(s);

  const VIEW_W = 100;
  const VIEW_H = 75;

  /**
   * Slack around the drawing area, in the same units.
   *
   * Locations are authored at 0–100 by 0–75, but two things are drawn outside
   * that box: a node's name sits under it, and a road leaving the region is
   * captioned past its own stub. Without margin the viewBox clips both, which
   * reads as a rendering fault rather than as a map that goes on.
   */
  const PAD_X = 9;
  /** A little slack top and bottom so edge labels and out-of-region captions
   *  are not clipped by the viewBox. */
  const PAD_TOP = 4;
  const PAD_BOTTOM = 8;

  /**
   * Index of direct exits from the current scene, keyed by destination id.
   *
   * Built once per draw so the edge, node and neighbour renderers all agree
   * on which places are "a way out from here" without re-walking the list.
   *
   * @param {{ id:number, locked?:boolean, label?:string }[]} ways
   * @returns {Map<number, { id:number, locked:boolean, label:string }>}
   */
  /**
   * Where the outstanding stages want the party, keyed by location.
   *
   * Two quests can want the same place; the tracked one wins the pin, because
   * that is the one the tracker is talking about and two marks on one name is
   * just a smudge. The rest still contribute their titles to the tooltip.
   *
   * @param {{ id:number, tracked?:boolean, title?:string, objective?:string }[]} quests
   */
  function questIndex(quests) {
    const idx = new Map();
    (quests || []).forEach((q) => {
      if (!q || q.id == null) return;
      const id = +q.id;
      // What the pin's own tooltip says: the quest, and what it wants done.
      // Tab-joined so the tooltip can style the two halves apart; newlines
      // separate quests when several want the same place.
      const line = [q.title, q.objective].filter(Boolean).join('\t');
      const prev = idx.get(id);
      if (!prev) {
        idx.set(id, {
          tracked: !!q.tracked,
          titles: [q.title].filter(Boolean),
          lines: [line].filter(Boolean),
        });
        return;
      }
      prev.tracked = prev.tracked || !!q.tracked;
      if (q.title) prev.titles.push(q.title);
      if (line) prev.lines.push(line);
    });
    return idx;
  }

  function wayIndex(ways) {
    const idx = new Map();
    (ways || []).forEach((w) => {
      if (!w || w.id == null) return;
      idx.set(+w.id, {
        id: +w.id,
        locked: !!w.locked,
        label: w.label || '',
      });
    });
    return idx;
  }

  /**
   * The chart.
   *
   * Edges are drawn first so the nodes sit on top of them. Roads to unvisited
   * places are still drawn, dimmed: the shape of the region is worth knowing
   * even where the names are not, and a road going somewhere unnamed is the
   * thing that gives the player something to aim at.
   *
   * @param {object} map region_map payload
   * @param {{ ways?: { id:number, locked?:boolean, label?:string }[],
   *           quests?: { id:number, tracked?:boolean, title?:string, objective?:string }[],
   *           party?: { name:string, face:string, hp?:number, max_hp?:number,
   *                     level?:number, active?:boolean }[] }} [opts]
   *        `ways` are direct exits from the current scene, highlighted as the
   *        ways out. `quests` are where outstanding stages want the party, each
   *        drawn as a ? over the place-name. `party` is who is standing in this
   *        scene, drawn as faces under the place-name — there is one party and
   *        it is in one place, so they all land on the `current` node.
   */
  function svg(map, opts) {
    if (!map || !map.nodes || !map.nodes.length) {
      return '<p class="help-hint">Nowhere charted yet.</p>';
    }
    const byId = new Map(map.nodes.map((n) => [n.id, n]));
    const ways = wayIndex(opts && opts.ways);
    const quests = questIndex(opts && opts.quests);
    const here = map.nodes.find((n) => n.current);
    const hereId = here ? here.id : null;

    // Corridors, keyed by the pair they join, when this region is a generated
    // dungeon floor. An edge that has one is drawn as the passage it actually
    // On a floor plan the passages ARE the edges — each one is drawn as its own
    // run in floorplan(), and it is a location in its own right, so a line from
    // the room to it would be a second drawing of the same join. Everywhere
    // else an edge is a line between two names, as it always was.
    const edges = map.floorplan ? '' : (map.edges || []).map((e) => {
      const a = byId.get(e.from);
      const b = byId.get(e.to);
      if (!a || !b) return '';
      const known = a.visited && b.visited;
      // A road that is a direct way out of the current scene is drawn solid
      // and bright, so the eye finds "where can I go" before it finds the
      // rest of the country.
      const isWay = hereId != null && (
        (e.from === hereId && ways.has(e.to)) ||
        (e.to === hereId && ways.has(e.from))
      );
      const way = isWay ? (ways.get(e.to === hereId ? e.from : e.to)) : null;
      const cls = [
        'wm-edge',
        known ? 'is-known' : '',
        isWay ? 'is-way' : '',
        way && way.locked ? 'is-locked' : '',
      ].filter(Boolean).join(' ');

      return `<line class="${cls}"
        x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}"/>`;
    }).join('');

    const arrows = (map.neighbors || [])
      .map((n) => neighborArrow(n, byId, ways, hereId))
      .filter(Boolean)
      .join('');
    // A generated floor is drawn at room scale — its labels are a third
    // smaller than a region's — so the faces come down with them. A pile of
    // full-size tokens under a room name spills out of the room it is in.
    const marks = map.floorplan ? partyMarks(opts && opts.party, 0.78) : partyMarks(opts && opts.party, 1);
    // Nothing names a place nobody has walked into, on a floor plan as much as
    // on a region. A generated floor used to name every room, on the argument
    // that the plan already drew the whole level so there was nothing left to
    // protect — that argument died with the fog: the plan now draws what the
    // party has seen, and a name over an unvisited room would be the one thing
    // on the chart that still knew the whole floor.
    const nodes = map.nodes.map((n) => node(n, ways, quests, marks)).filter(Boolean).join('');

    // A painted map for this region, if one has been drawn. Optional by
    // design: with no file the chart falls back to the parchment ground and
    // its own ink roads, and the names and click targets are identical either
    // way. The art is a backdrop; the interaction is never in the image.
    //
    // Drawn to the 0–100 by 0–75 field the locations are authored against, so
    // a node at map_pos [45, 26] sits at the same place on the painting as it
    // does on the bare chart.
    //
    // Not attempted at all on a generated floor. There is no plate for a
    // region that exists only while a party is standing in it, and asking for
    // one would fetch the homepage HTML and get a 200 for it — the vhost's
    // try_files trap, which is why nothing here verifies art by status code.
    const art = (map.region_key && !map.floorplan)
      ? `<image class="wm-art" href="assets/images/maps/${encodeURIComponent(map.region_key)}.png"
                x="0" y="0" width="${VIEW_W}" height="${VIEW_H}"
                preserveAspectRatio="none"
                onerror="this.closest('svg').classList.add('is-bare');this.remove()"/>`
      : '';

    const plan = floorplan(map.floorplan, hereId, ways);

    // `is-bare` means "nothing painted behind the ink", and the roads keep
    // their full weight when it is set. It used to be added only by the
    // <image>'s own onerror, which meant it was set when a plate was MISSING
    // and never when no plate was asked for — so a floor plan, which requests
    // no image at all, would have had its corridors dimmed to the 0.22 that is
    // meant for lines lying over a painting. Set here too, from the same fact
    // stated up front rather than discovered by a failed fetch.
    const cls = ['worldmap', art ? '' : 'is-bare', map.floorplan ? 'is-plan' : '']
      .filter(Boolean).join(' ');

    return `<svg class="${cls}"
                 viewBox="${-PAD_X} ${-PAD_TOP} ${VIEW_W + PAD_X * 2} ${VIEW_H + PAD_TOP + PAD_BOTTOM}"
                 role="img" aria-label="Map of ${esc(map.name)}">
      <defs>
        <filter id="wm-ink" x="-20%" y="-20%" width="140%" height="140%">
          <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" seed="7"/>
          <feDisplacementMap in="SourceGraphic" scale="0.35"/>
        </filter>
      </defs>
      ${art}
      ${plan}
      <g class="wm-edges">${edges}${arrows}</g>
      <g class="wm-nodes">${nodes}</g>
    </svg>`;
  }

  // =========================================================================
  // The party, standing where they are
  // =========================================================================

  /** Faces beyond this become a "+N" disc rather than a longer pile. */
  const PC_MAX = 5;
  /** Radius of one face, in chart units — a little under a name's height. */
  const PC_R = 1.7;
  /** Centre-to-centre. Under 2R on purpose: they overlap, like people do. */
  const PC_STEP = 2.3;
  /** How far under the place-name the pile sits. */
  const PC_Y = 4.4;

  /**
   * The party as a pile of faces, ready to be dropped into the current node.
   *
   * Returns markup, not a component: it is rendered once and only the node
   * with `current` set is given it, because there is one party and it is in
   * one place. Everything is positioned relative to the node's own origin, so
   * it travels with the name when the chart pans and zooms.
   *
   * A face is an <image> over a lettered disc, and the image removes itself if
   * it fails to load — so a character whose portrait is missing shows their
   * initial rather than a broken tile. That matters more here than elsewhere:
   * a missing static file on this host serves the homepage HTML with a 200,
   * so `onerror` is the only thing that ever finds out.
   */
  function partyMarks(party, scale) {
    const all = (party || []).filter((p) => p && p.name);
    if (!all.length) return '';

    const r = PC_R * scale;
    const step = PC_STEP * scale;
    const y = PC_Y * scale;

    // The clip id carries the radius. Ids are document-wide, and a bench that
    // draws several charts at once — tools/floorplan_preview.php — would
    // otherwise have every face on the page cropped by whichever chart
    // rendered first. Same scale, same id, and the duplicates are identical.
    const clipId = `wm-pc-clip-${Math.round(r * 100)}`;

    const shown = all.slice(0, PC_MAX);
    const extra = all.length - shown.length;
    const cols = shown.length + (extra > 0 ? 1 : 0);
    const x0 = -((cols - 1) * step) / 2;

    const faces = shown.map((pc, i) => {
      const down = pc.hp != null && +pc.hp <= 0;
      const cls = ['wm-pc', down ? 'is-down' : '', pc.active ? 'is-active' : '']
        .filter(Boolean).join(' ');
      const hp = (pc.hp != null && pc.max_hp != null) ? ` — ${pc.hp}/${pc.max_hp} hp` : '';
      const title = `${pc.name}${pc.level != null ? `, level ${pc.level}` : ''}${hp}`
        + (down ? ' — down' : '');
      const initial = (pc.name.trim()[0] || '?').toUpperCase();
      return `<g class="${cls}" transform="translate(${(x0 + i * step).toFixed(2)} ${y.toFixed(2)})">
        <title>${esc(title)}</title>
        <circle class="wm-pc-disc" r="${r.toFixed(2)}"/>
        <text class="wm-pc-initial" dy="${(r * 0.36).toFixed(2)}"
              font-size="${(r * 1.15).toFixed(2)}">${esc(initial)}</text>
        ${pc.face ? `<image class="wm-pc-face" href="${esc(pc.face)}"
          x="${(-r).toFixed(2)}" y="${(-r).toFixed(2)}"
          width="${(r * 2).toFixed(2)}" height="${(r * 2).toFixed(2)}"
          clip-path="url(#${clipId})" preserveAspectRatio="xMidYMid slice"
          onerror="this.remove()"/>` : ''}
        <circle class="wm-pc-ring" r="${r.toFixed(2)}"/>
      </g>`;
    // Reversed, so the pile stacks left-over-right: the faces are laid out by
    // index but painted back to front, and SVG paints in document order. The
    // first member of the party — the one the rail puts at the top, and the
    // one carrying the active ring — would otherwise be the single face the
    // pile hides most of.
    }).reverse().join('');

    const more = extra > 0
      ? `<g class="wm-pc is-more" transform="translate(${(x0 + shown.length * step).toFixed(2)} ${y.toFixed(2)})">
          <title>${esc(all.slice(PC_MAX).map((p) => p.name).join(', '))}</title>
          <circle class="wm-pc-disc" r="${r.toFixed(2)}"/>
          <text class="wm-pc-initial" dy="${(r * 0.36).toFixed(2)}"
                font-size="${(r * 1.0).toFixed(2)}">+${extra}</text>
          <circle class="wm-pc-ring" r="${r.toFixed(2)}"/>
        </g>`
      : '';

    // The clip travels with the faces rather than living in the chart's own
    // <defs>, because its radius is the one above and a scale away from it is
    // a circle that crops the wrong amount off every portrait.
    return `<g class="wm-party">
      <clipPath id="${clipId}"><circle r="${r.toFixed(2)}"/></clipPath>
      ${more}${faces}
    </g>`;
  }

  // `corridorIndex` and `pairKey` used to live here, matching a drawn run
  // against the edge between the two rooms it joined. There is no such edge
  // now — the graph runs room, passage, room — and a passage carries its own
  // location id, so it is looked up as the place it is rather than as the gap
  // between two others.

  /** How wide a passage is drawn, in chart units. */
  const HALL_W = 2.4;
  /** How thick its walls are, per side. */
  const HALL_WALL = 0.45;

  /**
   * One door glyph, in the old-school grammar: the reader learns the
   * vocabulary once and reads every floor with it.
   *
   *   door       a hollow square across the threshold
   *   locked     the square, barred along the passage
   *   trapped    the square, crossed out (drawn only once the party knows)
   *   portcullis a dotted line across the way
   *   stuck      a dashed seam, with donjon's little `s` beside it — drawn
   *              only once found; planFor strips it before then
   *   open/arch  nothing. A hole in a wall marked on every join would put a
   *              symbol on most of the floor and so tell you nothing.
   *
   * `vertical` is the direction the corridor ARRIVES from, so the glyph is
   * drawn across the way, not along it.
   */
  function doorGlyph(door, x, y, vertical) {
    const across = HALL_W / 2 + HALL_WALL + 0.2;   // just proud of the walls
    const along = 0.55;
    const boxAttrs = vertical
      ? `x="${x - across}" y="${y - along}" width="${across * 2}" height="${along * 2}"`
      : `x="${x - along}" y="${y - across}" width="${along * 2}" height="${across * 2}"`;

    if (door === 'portcullis') {
      const [dx, dy] = vertical ? [across, 0] : [0, across];
      return `<line class="wm-portcullis" x1="${x - dx}" y1="${y - dy}" x2="${x + dx}" y2="${y + dy}"/>`;
    }
    if (door === 'stuck') {
      const [dx, dy] = vertical ? [across, 0] : [0, across];
      const [sx, sy] = vertical ? [x + across + 0.9, y + 0.8] : [x + 1.1, y - across - 0.4];
      return `<line class="wm-door is-stuck" x1="${x - dx}" y1="${y - dy}" x2="${x + dx}" y2="${y + dy}"/>
        <text class="wm-secret-s" x="${sx}" y="${sy}">s</text>`;
    }
    if (door === 'door' || door === 'locked' || door === 'trapped') {
      let inner = '';
      if (door === 'locked') {
        const [dx, dy] = vertical ? [0, along] : [along, 0];
        inner = `<line class="wm-door-bar" x1="${x - dx}" y1="${y - dy}" x2="${x + dx}" y2="${y + dy}"/>`;
      }
      if (door === 'trapped') {
        inner = `<line class="wm-door-bar" x1="${x - 0.45}" y1="${y - 0.45}" x2="${x + 0.45}" y2="${y + 0.45}"/>
          <line class="wm-door-bar" x1="${x - 0.45}" y1="${y + 0.45}" x2="${x + 0.45}" y2="${y - 0.45}"/>`;
      }
      return `<rect class="wm-doorbox" ${boxAttrs}/>${inner}`;
    }
    return '';
  }

  /**
   * A stair, as a run of shortening ticks — the glyph every printed module
   * used, and the shortening is the direction: toward the short end is down.
   * The entrance room gets the mirrored run, because its stair goes up.
   */
  function stairTicks(r, up) {
    const cx = r.x + r.w / 2;
    // Upper third, not centre: the room's NAME owns the centre — it is the
    // click target — and a glyph under ink is two things saying one thing.
    const cy = r.y + r.h * 0.3;
    const wide = r.w >= r.h;   // ticks perpendicular to the room's long axis
    const ticks = [];
    for (let i = 0; i < 5; i++) {
      const t = up ? i : 4 - i;
      const half = 0.55 + t * 0.28;
      const off = (i - 2) * 0.95;
      ticks.push(wide
        ? `<line x1="${cx + off}" y1="${cy - half}" x2="${cx + off}" y2="${cy + half}"/>`
        : `<line x1="${cx - half}" y1="${cy + off}" x2="${cx + half}" y2="${cy + off}"/>`);
    }
    return `<g class="wm-stair-ticks" aria-hidden="true">${ticks.join('')}</g>`;
  }

  /**
   * A generated floor: rooms, the passages between them, and the doors.
   *
   * A PASSAGE IS DRAWN AS A PASSAGE, not as a line. It used to be a hairline
   * polyline drawn with the edges, and a level came out reading as a flowchart
   * — boxes floating in space with threads between them — rather than as
   * somewhere underground. It is now a run with a floor and two walls, and it
   * is a location the party can be standing in.
   *
   * The walls come from stroking the same polyline twice: once thick in the
   * wall colour, then once thinner in the floor colour on top. That is what
   * gets the corners right. Mitring two parallel offset lines round a dogleg is
   * real geometry and would be a second copy of a rule in JavaScript, which is
   * the thing this file does not do; two strokes and a round join are exact,
   * for free, at every angle.
   *
   * Order matters and is the whole trick: every passage's walls go down first,
   * then every passage's floor, then the rooms on top. The rooms cover the wall
   * caps where a run meets a doorway, so a passage stops at the wall it enters
   * rather than poking into the room.
   *
   * Unlike the rooms, a passage IS clickable — it is a destination like any
   * other, and it has no name on the chart to click instead.
   *
   * The whole floor is drawn, not just what has been walked. That is not a
   * decision this function makes — the nodes were already all on the chart,
   * because DelveEngine writes rooms with `hidden_until_visited` unset, and a
   * plan that hid what the labels already show would be the odd one out.
   */
  function floorplan(plan, hereId, ways) {
    if (!plan || !plan.rooms) return '';

    // The rock the level is cut from. Under everything, out to the padded
    // edges of the viewBox, so the plate reads as a hole in the dark rather
    // than shapes floating on parchment — the parchment stays outside the
    // frame, on .map-body, where the region charts keep it.
    const rock = `<rect class="wm-rock" x="${-PAD_X}" y="${-PAD_TOP}"
      width="${VIEW_W + PAD_X * 2}" height="${VIEW_H + PAD_TOP + PAD_BOTTOM}"/>`;

    // The fog, drawn. `seen` and `glimpsed` are DelveEngine::fog()'s answer for
    // this party; a shape with neither is not drawn at all, which is the whole
    // of the mechanism — there is no mask, no second layer and nothing on the
    // client deciding what is hidden. A glimpsed shape is an outline: you have
    // seen the doorway into it and nothing else, so it gets no ruling, no
    // stair ticks and no name.
    //
    // `!== false` rather than a truth test, deliberately: an authored region
    // has no floor plan at all and a plan from a payload that predates the fog
    // has neither flag. Both read as "draw it", which is what they meant.
    const lit = (o) => o.seen !== false;
    const shown = (o) => o.seen !== false || o.glimpsed === true;

    const seenRooms = plan.rooms.filter(shown);
    const rooms = seenRooms.map((r) => {
      const cls = [
        'wm-room',
        lit(r) ? '' : 'is-glimpsed',
        r.location_id === hereId ? 'is-here' : '',
        lit(r) && r.role === 'stair' ? 'is-stair' : '',
        lit(r) && r.role === 'entrance' ? 'is-entrance' : '',
      ].filter(Boolean).join(' ');
      return `<rect class="${cls}" x="${r.x}" y="${r.y}"
        width="${r.w}" height="${r.h}"/>`;
    }).join('');

    const runs = (plan.corridors || [])
      .filter((c) => (c.points || []).length >= 2 && shown(c));
    const points = (c) => c.points.map((p) => p.join(',')).join(' ');

    const walls = runs.map((c) => `<polyline class="wm-hall-wall${lit(c) ? '' : ' is-glimpsed'}"
      points="${points(c)}" stroke-width="${HALL_W + HALL_WALL * 2}"/>`).join('');

    const floors = runs.map((c) => {
      const way = ways && c.location_id != null ? ways.get(c.location_id) : null;
      const cls = [
        'wm-hall',
        lit(c) ? '' : 'is-glimpsed',
        c.location_id != null && c.location_id === hereId ? 'is-here' : '',
        way ? 'is-way' : '',
        way && way.locked ? 'is-locked' : '',
        // `is-unfound` was here — a dimmed, doorless run for a secret way
        // nobody had found. A passage behind an unfound secret door is not
        // drawn at all now: DelveEngine::fog() marks it neither seen nor
        // glimpsed, so it never reaches the filter above.
      ].filter(Boolean).join(' ');
      // Clickable only when it is somewhere to go. A passage two rooms away is
      // drawn — the level's shape is worth seeing — but travel is a click on
      // somewhere you can get to, and the rest of the chart pans under a drag.
      const target = c.location_id != null && c.location_id !== hereId
        ? ` data-map-node="${c.location_id}" role="button" tabindex="0"`
        : '';
      return `<g class="wm-hall-g"${target}>
        ${c.name && lit(c) ? `<title>${esc(c.name)}</title>` : ''}
        <polyline class="${cls}" points="${points(c)}" stroke-width="${HALL_W}"/>
      </g>`;
    }).join('');

    // The graph-paper ruling, as plain line elements in one path per kind —
    // a room is ruled both ways from its own corner, a corridor is ticked
    // across its run every cell. Nothing clever: no <pattern>, no <mask>.
    // Both of those were tried and both, repainted on scroll, froze Chrome's
    // renderer solid — a pattern-stroked polyline is apparently a paint the
    // rasteriser will sit down and think about. A few hundred hairlines in
    // one <path> is nothing. Cell = HALL_W, so a corridor reads as one
    // square wide: the 10-foot corridor of every graph-paper dungeon.
    // Ruled only where the party has been. A glimpsed room is a doorway seen
    // from a passage, and graph paper inside it would be claiming its floor.
    let rules = '';
    plan.rooms.filter(lit).forEach((r) => {
      for (let gx = HALL_W; gx < r.w - 0.05; gx += HALL_W) {
        rules += `M ${r.x + gx} ${r.y} v ${r.h} `;
      }
      for (let gy = HALL_W; gy < r.h - 0.05; gy += HALL_W) {
        rules += `M ${r.x} ${r.y + gy} h ${r.w} `;
      }
    });
    const tickHalf = HALL_W / 2;
    runs.filter(lit).forEach((c) => {
      const pts = c.points;
      for (let k = 0; k < pts.length - 1; k++) {
        const [x1, y1] = pts[k];
        const [x2, y2] = pts[k + 1];
        const vert = Math.abs(x2 - x1) < 1e-6;
        const len = vert ? Math.abs(y2 - y1) : Math.abs(x2 - x1);
        const step = (vert ? Math.sign(y2 - y1) : Math.sign(x2 - x1)) * HALL_W;
        for (let d = HALL_W; d < len - 0.05; d += HALL_W) {
          const t = d / HALL_W;
          rules += vert
            ? `M ${x1 - tickHalf} ${y1 + step * t} h ${HALL_W} `
            : `M ${x1 + step * t} ${y1 - tickHalf} v ${HALL_W} `;
        }
      }
    });
    const grid = `<path class="wm-grid-line" d="${rules}"/>`;

    // The stairs, in ticks; the way down shortens, the way up widens.
    // The way down is a thing you find by walking into the room that holds it.
    const stairs = plan.rooms.filter(lit).map((r) => {
      if (r.role === 'stair') return stairTicks(r, false);
      if (r.role === 'entrance') return stairTicks(r, true);
      return '';
    }).join('');

    // One glyph per threshold, and each doorway has two — at_a is the wall
    // the corridor leaves, at is the wall it arrives at. Orientation comes
    // from the segment actually meeting that wall, so a dogleg's two glyphs
    // can face different ways and both sit square across their own door.
    const doors = runs.map((c) => {
      if (!c.door || c.door === 'open' || c.door === 'arch') return '';
      const pts = c.points;
      const ends = [
        { p: c.at_a || pts[0], n: pts[1] },
        { p: c.at || pts[pts.length - 1], n: pts[pts.length - 2] },
      ];
      return ends.map(({ p, n }) =>
        doorGlyph(c.door, p[0], p[1], Math.abs(n[0] - p[0]) < 1e-6)).join('');
    }).join('');

    // Traps the party has met, one mark each: hollow where it was spotted,
    // filled where it was sprung. Unmet traps never reach the client.
    const trapMarks = runs.map((c) => {
      if (!c.trap || typeof c.trap !== 'object') return '';
      const [x, y] = c.mid || c.points[0];
      return `<path class="wm-trap is-${esc(c.trap.state)}"
        d="M ${x} ${y - 0.9} L ${x + 0.85} ${y + 0.65} L ${x - 0.85} ${y + 0.65} Z">
        <title>${esc(c.trap.state === 'sprung' ? 'A trap, already sprung' : 'A trap, marked and avoided')}</title>
      </path>`;
    }).join('');

    return `<g class="wm-plan">${rock}${walls}${floors}${rooms}${grid}${stairs}${doors}${trapMarks}</g>`;
  }

  /**
   * One place — its name is the whole of what is drawn, except where the party
   * is standing, which also carries their faces (`marks`, rendered once by
   * `partyMarks` and handed to whichever node is `current`).
   *
   * The label is the click target (with an invisible hit pad under it). The
   * current location is not clickable: travelling to where you already are is
   * an error the server would rightly refuse, so the UI does not offer it.
   *
   * A direct exit from the current scene is highlighted as text.
   *
   * A place the party has not been keeps its name back, and how much is drawn
   * at all depends on how close it is:
   *
   *   - a direct exit from this scene is an empty ring, so it can still be
   *     clicked — going in is what names it;
   *   - anywhere further off is not drawn, and returns '' from here. Its roads
   *     are still drawn by the edge pass, so the chart says a way goes on
   *     without saying where. A nameless label would be a mystery click
   *     target; an empty one at a known position is worse.
   *
   * `named` overrides all of that, and a generated dungeon floor sets it. On a
   * floor plan every room is already DRAWN — the rectangles are the level's
   * actual shape — so withholding the names left a chart of blank boxes with
   * one red name in it and its neighbours reduced to marks. Exploration is a
   * rule about an authored region, where not knowing what is over the hill is
   * the point; applied to a map you are holding it just made the map useless.
   *
   * Genuinely hidden places are not on the chart at all until found — see
   * `hidden_until_visited`, which is a stronger thing than being unvisited.
   */
  function node(n, ways, quests, marks) {
    // A passage has no label — the chart draws its RUN, in floorplan(), and
    // that run is the click target. Naming it would put "A Low Way" across the
    // rooms either side of it, twelve times a level, for a place whose whole
    // significance is that you go through it. All this leaves behind is the
    // party's own faces when they are standing in one: those follow the party
    // wherever it is, and a corridor is now somewhere it can be.
    if (n.type === 'passage') {
      return n.current && marks
        ? `<g class="wm-node is-passage is-here" transform="translate(${n.x} ${n.y})">
             <title>${esc(n.name)} — you are here</title>${marks}</g>`
        : '';
    }

    const way = ways.get(n.id);
    const quest = quests && quests.get(n.id);
    const unknown = !n.visited && !n.current;
    if (unknown && !way) return '';

    const cls = [
      'wm-node',
      `is-${esc(n.type)}`,
      n.visited ? 'is-visited' : 'is-unknown',
      unknown ? 'is-unnamed' : '',
      n.current ? 'is-here' : '',
      way ? 'is-way' : '',
      way && way.locked ? 'is-locked' : '',
      quest ? 'has-quest' : '',
    ].filter(Boolean).join(' ');

    // Nothing here may name an unvisited place — not the label, not the
    // tooltip, and not the exit's own label either, which falls back to the
    // destination's name when the author gave the road no name of its own.
    let title;
    if (n.current) {
      title = `${n.name} — you are here`;
    } else if (unknown) {
      title = way.locked
        ? 'Somewhere you have not been — shut to you for now'
        : 'Somewhere you have not been';
    } else if (way) {
      title = way.locked
        ? `${way.label || n.name} — shut to you for now`
        : (way.label ? `${way.label} — ${n.name}` : `Travel to ${n.name}`);
    } else {
      title = `Travel to ${n.name}`;
    }
    // The pin says a stage wants them here; the tooltip says which. A quest
    // title is the quest's, not the place's, so it is safe on an unnamed node.
    if (quest) title += `\n? ${quest.titles.join(' · ') || 'Something wants you here'}`;

    // A place is its name and nothing else. There used to be a number in front
    // of it — the exit's keyboard key — and where the place was unknown the
    // number was the whole label. Those keys index the exits of whatever scene
    // you are standing in, so they were renumbered by every step you took: the
    // "2" on the map was a different room a moment later, and a room that had
    // just been given its name left its old number behind for somebody else.
    // The keys are gone from the game; a way you have not walked is drawn as an
    // empty ring instead, which says the same thing without pretending to be a
    // name or an index.
    const mark = unknown
      ? '<circle class="wm-unwalked" cx="0" cy="0" r="1.5"/>'
      : `<text class="wm-label" dy="0.85">${esc(n.name)}</text>`;

    return `<g class="${cls}" transform="translate(${n.x} ${n.y})"
      ${n.current ? '' : `data-map-node="${n.id}" role="button" tabindex="0"`}>
      <title>${esc(title)}</title>
      <rect class="wm-hit" ${unknown ? 'x="-3.2" width="6.4"' : 'x="-10" width="20"'}
            y="-4" height="8" rx="1"/>
      ${mark}
      ${quest ? `<g data-quest-pin
          data-quest-info="${esc((quest.lines.length ? quest.lines : ['Something wants you here']).join('\n'))}">
        <circle class="wm-quest-hit" cx="0" cy="-4.2" r="3.4"/>
        <text class="wm-quest${quest.tracked ? ' is-tracked' : ''}"
          dy="-2.9" aria-hidden="true">?</text>
      </g>` : ''}
      ${n.current ? (marks || '') : ''}
    </g>`;
  }

  /**
   * A road leaving the region.
   *
   * Drawn as a stub running from the border location toward the nearest edge
   * of the chart, captioned with the neighbouring region's name. It is
   * clickable for the same reason the place names are: "through the east
   * gate" is a journey the server can route.
   *
   * When the stub is a direct exit from the current scene its caption is
   * highlighted the same way an in-region way-out is, with the number key in
   * the text.
   *
   * A region the party has never crossed into is under the same rule as an
   * unvisited place: its name is withheld. From the scene the road leaves, the
   * stub is its number key; from anywhere else it is a bare road out, drawn
   * but not captioned and not clickable. `visited` here is of the location on
   * the far side, which is what "we have been through there" means.
   */
  function neighborArrow(nb, byId, ways, hereId) {
    const from = byId.get(nb.from);
    if (!from) return '';
    // Push toward whichever border the source location is already nearest, so
    // the stub leaves the chart the way the road actually goes.
    const dx = from.x > VIEW_W / 2 ? 1 : -1;
    const dy = from.y > VIEW_H / 2 ? 0.35 : -0.35;
    const tx = Math.max(4, Math.min(VIEW_W - 4, from.x + dx * 11));
    const ty = Math.max(5, Math.min(VIEW_H - 5, from.y + dy * 11));
    const anchor = dx > 0 ? 'start' : 'end';

    const way = ways.get(nb.to_location_id);
    const isWayFromHere = hereId != null && nb.from === hereId && !!way;
    const unknown = !nb.visited;
    const cls = [
      'wm-neighbor',
      isWayFromHere ? 'is-way' : '',
      unknown ? 'is-unnamed' : '',
      way && way.locked ? 'is-locked' : '',
    ].filter(Boolean).join(' ');

    let title;
    if (unknown) {
      title = 'A road out of the region — you have not been this way';
    } else {
      title = `${nb.label} — to ${nb.region_name}`;
    }
    if (way && way.locked) title += ' — shut to you for now';

    // No number here either, for the reason node() gives. An uncrossed road out
    // of the region is captioned with an arrow alone when it is a step from
    // here, and with nothing at all when it is not.
    let caption;
    if (unknown) caption = isWayFromHere ? '→' : '';
    else caption = `${nb.region_name} →`;

    // Unnamed and not a step from here: the road is drawn and nothing else.
    // Clicking a caption you cannot read is not travel, it is a guess.
    const clickable = !unknown || isWayFromHere;

    return `<g class="${cls}"${clickable
        ? ` data-map-node="${nb.to_location_id}" role="button" tabindex="0"`
        : ' aria-hidden="true"'}>
      <title>${esc(title)}</title>
      <line class="wm-edge is-out${isWayFromHere ? ' is-way' : ''}${way && way.locked ? ' is-locked' : ''}"
            x1="${from.x}" y1="${from.y}" x2="${tx}" y2="${ty}"/>
      <text class="wm-out-label" x="${tx + dx * 2.4}" y="${ty + 0.9}"
            text-anchor="${anchor}">${esc(caption)}</text>
    </g>`;
  }

  // =========================================================================
  // Zoom and pan
  //
  // The mechanism lives in svg-view.js — viewBox arithmetic, the cursor-anchored
  // zoom, the clamp and the pointer state machine, none of which knows what it
  // is looking at. What stays here is everything that does: which drags belong
  // to the map rather than to a place-name, where the chart opens, and the
  // `is-zoomed` class the walk reads.
  //
  // It was 175 lines in this file, and ui-battlemap.js's header said in so many
  // words that the day the board wanted the same thing, this should be
  // extracted rather than copied. That day came.
  // =========================================================================

  const ZOOM_MIN = 1;      // the whole region, fitted
  const ZOOM_MAX = 6;
  const ZOOM_STEP = 1.35;  // per button press or wheel notch
  // Open roughly halfway between fitted and close — enough to read the street
  // around you without losing the rest of the town to the edge.
  const ZOOM_OPEN = 2.5;

  const BASE = {
    x: -PAD_X,
    y: -PAD_TOP,
    w: VIEW_W + PAD_X * 2,
    h: VIEW_H + PAD_TOP + PAD_BOTTOM,
  };

  /**
   * Authoring coordinates of "you are here", if the chart has one.
   *
   * Read off the rendered node rather than the map payload so the control
   * layer never has to be told a second time where the party stands.
   */
  function herePoint(svgEl) {
    const el = svgEl.querySelector('.wm-node.is-here');
    if (!el) return null;
    const m = /translate\(\s*([-\d.]+)[,\s]+([-\d.]+)\s*\)/.exec(el.getAttribute('transform') || '');
    if (!m) return null;
    return { x: +m[1], y: +m[2] };
  }

  /**
   * Give a rendered chart working zoom and pan.
   *
   * Called once per open. Starts zoomed in on where the party is (or the
   * middle of the region if that is missing), so you open the map looking at
   * the street you are on rather than the whole county.
   */
  function controls(root, saved, openZoom) {
    const svgEl = root.querySelector('.worldmap');
    if (!svgEl || !window.SvgView) return null;

    const here = herePoint(svgEl);
    const opens = openZoom || ZOOM_OPEN;
    const api = window.SvgView(svgEl, {
      base: BASE,
      zoomMin: ZOOM_MIN,
      zoomMax: ZOOM_MAX,
      zoomStep: ZOOM_STEP,
      // `saved` is a view a caller kept across a redraw. The scene chart is
      // rebuilt whenever anything in the scene changes, and without this every
      // rebuild threw away wherever the player had panned to.
      // `openZoom` is how close it opens when there is no saved view. The
      // modal opened at 2.5 because it filled the window; the chart in the
      // scene is a panel a third that size, and 2.5 there is a street with
      // nothing round it.
      start: saved || {
        z: opens,
        cx: here ? here.x : BASE.x + BASE.w / 2,
        cy: here ? here.y : BASE.y + BASE.h / 2,
      },
      // Left button, anywhere that is not a place-name. Labels keep the click
      // for travel; the rest of the chart is for looking around. Middle-button
      // still pans too, including over a label, for people who already use it.
      canPan: (e) => (e.button === 0 && !e.target.closest('[data-map-node]'))
        || e.button === 1,
      onApply: (view) => svgEl.classList.toggle('is-zoomed', view.z > 1.001),
    });
    if (!api) return null;

    CONTROLS.set(svgEl, api);
    return api;
  }

  // =========================================================================
  // Walking
  // =========================================================================

  /**
   * The controls a rendered chart was given, so `walk` can pan the view.
   *
   * A WeakMap rather than a field on the module: a chart is thrown away with
   * its modal, and the entry has to go with it. Nothing else needs to know
   * that the two were ever introduced.
   */
  const CONTROLS = new WeakMap();

  /** One hop, and about the budget for a whole journey. */
  const WALK_MS = 900;
  /** A long route hurries, but not into a blur. */
  const WALK_MIN_HOP = 220;

  /**
   * Walk the party from where they stand along a route, then leave them there.
   *
   * Called between the server saying the trip happened and the client redrawing
   * the scene, so what is animated is a journey that has already been made —
   * this cannot fail, cannot be interrupted, and cannot disagree with the
   * server about where the party ended up. `points` is the route the server
   * actually walked, origin first: a trip cut short by an ambush is a shorter
   * list, and the tokens stop where the party stopped.
   *
   * Hand-rolled on rAF rather than handed to the Web Animations API because
   * the view has to follow the tokens when the chart is zoomed in — the
   * destination is usually off screen — and two independently-timed animations
   * of the same movement drift apart on a slow frame.
   *
   * @param {Element} root anything containing the chart
   * @param {{x:number,y:number}[]} points origin first, then each hop
   * @param {{ instant?: boolean }} [opts] `instant` jumps to the end, for
   *        prefers-reduced-motion — the party still ends up in the right place.
   * @returns {Promise<void>} resolves when they arrive, or at once if there is
   *          nothing to walk: no chart open, no tokens, or a route of one point.
   */
  function walk(root, points, opts) {
    const svgEl = root && root.querySelector('.worldmap');
    const group = svgEl && svgEl.querySelector('.wm-party');
    if (!svgEl || !group || !points || points.length < 2) return Promise.resolve();

    const from = points[0];
    // Distance travelled to the end of each leg, so a hop across the region
    // and a hop next door take time in proportion rather than a leg each.
    const legs = [];
    let total = 0;
    for (let i = 1; i < points.length; i++) {
      const d = Math.hypot(points[i].x - points[i - 1].x, points[i].y - points[i - 1].y);
      total += d;
      legs.push(total);
    }
    if (total <= 0) return Promise.resolve();

    // Out of the node they were standing in and onto the end of the node
    // layer, so they cross the region OVER the places they pass rather than
    // sliding under their names. The faces are laid out around their node's
    // own origin, so once the group is off that node its transform is the
    // position itself rather than an offset from where they set out.
    //
    // Not put back afterwards, deliberately: they have arrived, they belong on
    // top at the destination too, and the chart is redrawn from the payload
    // the next time it is opened.
    const layer = svgEl.querySelector('.wm-nodes') || svgEl;
    layer.appendChild(group);

    const ctl = CONTROLS.get(svgEl);
    const place = (x, y) => {
      group.setAttribute('transform', `translate(${x.toFixed(3)} ${y.toFixed(3)})`);
      if (ctl && ctl.zoomed()) ctl.centerOn(x, y);
    };
    const end = points[points.length - 1];
    place(from.x, from.y);

    // Nothing to watch, so nothing to wait for. A background tab does not run
    // requestAnimationFrame at all, and the caller is holding the trip open
    // until this resolves — so an unwatched walk would leave the player's
    // party standing in a modal until they came back to the tab.
    if ((opts && opts.instant) || document.hidden) {
      place(end.x, end.y);
      return Promise.resolve();
    }

    const ms = Math.max(WALK_MS, WALK_MIN_HOP * legs.length);
    group.classList.add('is-walking');

    return new Promise((resolve) => {
      const started = performance.now();
      let settled = false;
      let backstop = 0;
      const done = () => {
        if (settled) return;
        settled = true;
        clearTimeout(backstop);
        group.classList.remove('is-walking');
        resolve();
      };
      // The same stall, entered halfway: the tab is hidden after the walk
      // starts and the frames stop coming. setTimeout is throttled in the
      // background but it still fires, so the trip always finishes.
      backstop = setTimeout(() => { place(end.x, end.y); done(); }, ms + 500);
      const frame = (now) => {
        if (settled) return;
        // The modal can be dismissed mid-walk. Nothing is lost by stopping —
        // the party has already moved on the server, and the chart that was
        // drawing them no longer exists.
        if (!group.isConnected) { done(); return; }
        const t = Math.min(1, (now - started) / ms);
        const p = at(points, legs, total, ease(t));
        place(p.x, p.y);
        if (t >= 1) { done(); return; }
        requestAnimationFrame(frame);
      };
      requestAnimationFrame(frame);
    });
  }

  /** Slow off the mark and slow into the doorway; brisk in between. */
  function ease(t) {
    return t < 0.5 ? 2 * t * t : 1 - ((-2 * t + 2) ** 2) / 2;
  }

  /** Where along the route a fraction of the total distance lands. */
  function at(points, legs, total, f) {
    const want = f * total;
    let i = 0;
    while (i < legs.length - 1 && legs[i] < want) i++;
    const before = i === 0 ? 0 : legs[i - 1];
    const span = legs[i] - before;
    const k = span > 0 ? (want - before) / span : 1;
    const a = points[i];
    const b = points[i + 1];
    return { x: a.x + (b.x - a.x) * k, y: a.y + (b.y - a.y) * k };
  }

  window.WorldMap = { svg, controls, walk };
})();
