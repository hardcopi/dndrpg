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

    // A token is one square, and the square is whatever the level says it is.
    //
    // This was a flat 0.78 on any floor plan, chosen by eye against the room
    // sizes of the day. Once the plan was ruled in its own ten-foot tiles the
    // number could be checked instead of judged, and it was wrong: a party
    // face came out 2.27 squares across and an NPC 1.94, so every creature on
    // the chart was standing in twenty feet of floor. Medium creatures take
    // one square, and now they take one.
    //
    // Derived rather than fixed because the pitch is not a constant — a floor
    // from the map service rules at about 1.17 units to the square and this
    // generator's own at 3.11, so any single number would be right for one of
    // them and wrong for the other. The smaller of the two axes, since the
    // chart stretches x and y by different amounts and a token has to fit the
    // square either way round.
    const ruling = map.floorplan && map.floorplan.grid;
    const scale = !map.floorplan ? 1
      : ruling && ruling.dx > 0.01 && ruling.dy > 0.01
        ? Math.min(ruling.dx, ruling.dy) / (PC_R * 2)
        : 0.78;
    // The pins are drawn at r=2 with their glyph and stroke weights written
    // to match, so they are scaled as a whole rather than re-specified: one
    // number here keeps the disc, the letter and the ring in proportion, and
    // the CSS goes on saying what it always said.
    //
    // A pin ends up about one square across, the same as a token. They were
    // sized against a chart that opened at 1.7 and a token that was two
    // squares wide; now that a plan opens on its own grid, a pin left at r=2
    // is nearly two squares and sits on the map like a dinner plate.
    const pinScale = ruling && ruling.dx > 0.01
      ? Math.min(ruling.dx, ruling.dy) / 4
      : 1;

    // The arrowhead, sized to the square where there is one and to the
    // region's own scale where there is not. About four fifths of a square
    // long on a plan, which is big enough to read against a hairline shaft
    // and small enough to sit inside the passage it is drawn over.
    const headLen = ruling && ruling.dx > 0.01 ? Math.min(ruling.dx, ruling.dy) * 0.85 : 1.8;
    const headHalf = ruling && ruling.dx > 0.01 ? Math.min(ruling.dx, ruling.dy) * 0.38 : 0.75;


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

      // The head goes on the end you would be walking TOWARD, which is the
      // end that is not the room you are standing in. An edge is stored in
      // whichever order it was authored, so it cannot be assumed to run away
      // from here.
      const outward = isWay
        ? (e.to === hereId ? a : b)
        : null;
      const shaft = `<line class="${cls}"
        x1="${a.x}" y1="${a.y}" x2="${b.x}" y2="${b.y}"/>`;
      if (!outward) return shaft;
      const tail = outward === b ? a : b;
      return shaft + arrowHead(tail.x, tail.y, outward.x, outward.y, headLen, headHalf,
        `wm-edge-head is-way${way && way.locked ? ' is-locked' : ''}`);
    }).join('');

    const arrows = (map.neighbors || [])
      .map((n) => neighborArrow(n, byId, ways, hereId, !!map.floorplan, pinScale, headLen, headHalf))
      .filter(Boolean)
      .join('');
    const marks = partyMarks(opts && opts.party, scale);
    // Somebody with a spot of their own is drawn on the chart rather than on
    // the place marker, so a shopkeeper can stand in his shop.
    const people = (opts && opts.people) || [];
    const atNode = people.filter((p) => !(Number.isFinite(+p.x) && Number.isFinite(+p.y)));
    const atSpot = people.filter((p) => Number.isFinite(+p.x) && Number.isFinite(+p.y));
    const folk = npcMarks(atNode, scale);
    const standing = npcMarks(atSpot, scale, { placed: true });
    // Nothing names a place nobody has walked into, on a floor plan as much as
    // on a region. A generated floor used to name every room, on the argument
    // that the plan already drew the whole level so there was nothing left to
    // protect — that argument died with the fog: the plan now draws what the
    // party has seen, and a name over an unvisited room would be the one thing
    // on the chart that still knew the whole floor.
    const nodes = map.nodes
      .map((n) => node(n, ways, quests, marks, folk, !!map.floorplan, pinScale))
      .filter(Boolean).join('');

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

    // The square's size, carried on the element so the zoom can find it.
    // controls() is handed a root and nothing else — it has never seen the map
    // object — and how far a floor plan should open is a question about the
    // grid, not about the chart's dimensions.
    const pitch = ruling && ruling.dx > 0.01 ? ` data-plan-pitch="${round3(Math.min(ruling.dx, ruling.dy))}"` : '';

    return `<svg class="${cls}"${pitch}
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
      <g class="wm-folk-placed">${standing}</g>
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
  /**
   * How far under the node's own point the pile sits.
   *
   * Zero: the party stands IN the room, on the spot the room is anchored at.
   *
   * It was 4.4 — a pile hung below a place-NAME, which is what a node used to
   * be on every chart. A floor plan has no name to hang off any more, and once
   * a token was one square rather than two the offset stopped reading as
   * "under the label" and started reading as "beside the room": at the current
   * scale it put the pile 1.4 units below centre, which in a passage drawn 2.4
   * wide is outside the passage altogether.
   */
  const PC_Y = 0;

  /**
   * Where the people who live here stand.
   *
   * Above the place, where the party is below it: the two never collide, and
   * the pair reads as "these are the locals, those are you". Same disc, ring
   * and clipped face as a party mark — a token is a token — but a little
   * smaller, because whose party this is should be the louder of the two.
   */
  const NPC_R = 1.45;
  const NPC_STEP = 2.0;
  /**
   * Still above the party rather than on it — the pair reads as "these are the
   * locals, those are you" — but close enough now to stay inside the room. At
   * the old -4.6 the locals stood a room's-worth clear of a floor drawn at the
   * new scale, which on a passage put them in the rock.
   */
  const NPC_Y = -3.2;
  const NPC_MAX = 4;

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

  /**
   * The people standing in this scene, as tokens over the place they are in.
   *
   * Clickable, and clickable by exactly the means everything else already
   * uses: `data-npc` is what game.js's delegate listens for, so a token opens
   * the same conversation the row in the HERE panel does. Two ways in to one
   * thing, which is the point — the panel is the list, the token is where they
   * are standing.
   *
   * A name is not written under them. Four names over one place is a wall of
   * text on a picture, and the tooltip carries it for a pointer while the
   * panel carries it for everyone else.
   */
  function npcMarks(people, scale, opts) {
    const all = (people || []).filter((p) => p && p.id);
    if (!all.length) return '';
    // Placed people carry their own spot on the chart and are drawn there;
    // everyone else is stacked at the place marker. Two passes over one
    // function so both kinds get the identical token.
    const placed = !!(opts && opts.placed);

    const r = NPC_R * scale;
    const step = NPC_STEP * scale;
    const y = NPC_Y * scale;
    const clipId = `wm-npc-clip-${Math.round(r * 100)}`;

    const shown = placed ? all : all.slice(0, NPC_MAX);
    const extra = placed ? 0 : all.length - shown.length;
    const cols = shown.length + (extra > 0 ? 1 : 0);
    const x0 = -((cols - 1) * step) / 2;

    const faces = shown.map((n, i) => {
      const label = n.label || n.name || 'Someone';
      const initial = (String(label).trim()[0] || '?').toUpperCase();
      const at = placed
        ? `${(+n.x).toFixed(2)} ${(+n.y).toFixed(2)}`
        : `${(x0 + i * step).toFixed(2)} ${y.toFixed(2)}`;
      return `<g class="wm-npc" transform="translate(${at})"
        data-npc="${esc(n.id)}" role="button" tabindex="0">
        <title>${esc(label)}${n.hint ? ` — ${esc(n.hint)}` : ''}</title>
        <circle class="wm-npc-disc" r="${r.toFixed(2)}"/>
        <text class="wm-npc-initial" dy="${(r * 0.36).toFixed(2)}"
              font-size="${(r * 1.15).toFixed(2)}">${esc(initial)}</text>
        ${n.face ? `<image class="wm-npc-face" href="${esc(n.face)}"
          x="${(-r).toFixed(2)}" y="${(-r).toFixed(2)}"
          width="${(r * 2).toFixed(2)}" height="${(r * 2).toFixed(2)}"
          clip-path="url(#${clipId})" preserveAspectRatio="xMidYMid slice"
          onerror="this.remove()"/>` : ''}
        <circle class="wm-npc-ring" r="${r.toFixed(2)}"/>
      </g>`;
    }).reverse().join('');

    const more = extra > 0
      ? `<g class="wm-npc is-more" transform="translate(${(x0 + shown.length * step).toFixed(2)} ${y.toFixed(2)})">
          <title>${esc(all.slice(NPC_MAX).map((n) => n.label || n.name).join(', '))}</title>
          <circle class="wm-npc-disc" r="${r.toFixed(2)}"/>
          <text class="wm-npc-initial" dy="${(r * 0.36).toFixed(2)}"
                font-size="${(r * 1.0).toFixed(2)}">+${extra}</text>
          <circle class="wm-npc-ring" r="${r.toFixed(2)}"/>
        </g>`
      : '';

    return `<g class="wm-folk">
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
  /** The stair glyph's own width, before it is scaled to its room. */
  const GLYPH_SPAN = 3.8;
  /** How thick its walls are, per side. */
  const HALL_WALL = 0.45;

  /* --- the ink pass -------------------------------------------------------

     What a hand-drawn plate has that a rectangle with a stroke on it does not
     is the ROCK: a heavy line round the floor, and hatching combed into the
     stone. The look is Dyson / Inkkeep: poisson-disk clusters of THREE
     parallel strokes, each cluster at an angle that is neither parallel nor
     square to its neighbours, clipped where they cross. A grey under-stroke
     of the same geometry sits in `shadow` so existing `.wm-rock-shadow` CSS
     reads as the fat collar under the ink.

     Patterns and masks over this chart freeze Chrome; a few thousand plain
     hairlines in one <path> do not. Jitter is hashed from coordinates so a
     redraw never crawls. */

  /** Sample spacing along the wall, in chart units. */
  const INK_STEP = 0.4;

  /**
   * Cluster hatch, scaled from Inkkeep's canvas (cell 14px, depth 18px)
   * into this chart (a ten-foot square is HALL_W = 2.4).
   */
  const HATCH_BAND = 3.05;
  const STROKE_SPACING = 0.45;
  const POISSON_R = STROKE_SPACING * 2;
  const STROKE_LEN = POISSON_R * 2;
  const NEIGHBOR_R = POISSON_R * 1.55;
  const MIN_STROKE = STROKE_LEN * 0.35;
  const HATCH_LIFT = 0.18;

  /** Whether a floor plan rules its rooms in ten-foot squares. See below. */
  const PLAN_RULED = true;

  /**
   * Deterministic noise in [0,1) from a position.
   *
   * Hand-drawn is jitter, but it has to be the SAME jitter every repaint: the
   * chart re-renders on travel, on zoom and on any state that touches the
   * plate, and hatching that reshuffled each time would crawl. So the jitter
   * is hashed from the coordinate rather than drawn from Math.random — the
   * same wall grows the same whiskers for as long as the level exists.
   */
  function inkNoise(x, y, salt) {
    let h = Math.imul(Math.round(x * 512) | 0, 0x27d4eb2d)
          ^ Math.imul(Math.round(y * 512) | 0, 0x165667b1)
          ^ Math.imul(salt | 0, 0x9e3779b1);
    h ^= h >>> 15; h = Math.imul(h, 0x2545f491);
    h ^= h >>> 13; h = Math.imul(h, 0x27d4eb2d);
    h ^= h >>> 16;
    return (h >>> 0) / 4294967296;
  }

  /**
   * Every rectangle of floor on a level, as [x0, y0, x1, y1].
   *
   * A passage is expanded only ACROSS its run, never along it — expanding both
   * ways would push each end half a passage-width further than the passage
   * goes, which at a dead end is a stub of floor sticking into the rock. The
   * corners that leaves are filled by a square at each turn.
   */
  function floorRects(plan, keep) {
    const rects = [];
    (plan.rooms || []).filter(keep).forEach((r) => {
      rects.push([r.x, r.y, r.x + r.w, r.y + r.h]);
    });
    const h = HALL_W / 2;
    (plan.corridors || [])
      .filter((c) => (c.points || []).length >= 2 && keep(c))
      .forEach((c) => {
        const pts = c.points;
        for (let k = 0; k < pts.length - 1; k++) {
          const [x1, y1] = pts[k];
          const [x2, y2] = pts[k + 1];
          if (Math.abs(x2 - x1) < 1e-6) {
            rects.push([x1 - h, Math.min(y1, y2), x1 + h, Math.max(y1, y2)]);
          } else {
            rects.push([Math.min(x1, x2), y1 - h, Math.max(x1, x2), y1 + h]);
          }
        }
        for (let k = 1; k < pts.length - 1; k++) {
          rects.push([pts[k][0] - h, pts[k][1] - h, pts[k][0] + h, pts[k][1] + h]);
        }
      });
    return rects;
  }

  /**
   * The rock, drawn: its edge, its hatching, its shadow and its rubble.
   *
   * Returns four path strings, each meant for one <path>. Empty when the level
   * has no lit floor yet, which is every level for the moment before the party
   * walks off the entrance stair.
   *
   * Hatch is Inkkeep's Dyson collar: poisson samples in a band of rock outside
   * the floor, three parallel strokes per sample, angles that refuse to sit
   * parallel or square to a neighbour, clipped at crossings. `shadow` is the
   * same geometry so a fat grey CSS stroke can sit under the ink.
   */
  function inkRock(rects) {
    if (!rects.length) return { edge: '', hatch: '', shadow: '', grit: '' };

    const EPS = 0.06;
    const inside = (x, y) => {
      for (let i = 0; i < rects.length; i++) {
        const r = rects[i];
        if (x > r[0] + EPS && x < r[2] - EPS && y > r[1] + EPS && y < r[3] - EPS) return true;
      }
      return false;
    };
    const isRock = (x, y) => !inside(x, y);

    const sides = (r) => [
      { x0: r[0], y0: r[1], x1: r[2], y1: r[1], nx: 0, ny: -1 },
      { x0: r[0], y0: r[3], x1: r[2], y1: r[3], nx: 0, ny: 1 },
      { x0: r[0], y0: r[1], x1: r[0], y1: r[3], nx: -1, ny: 0 },
      { x0: r[2], y0: r[1], x1: r[2], y1: r[3], nx: 1, ny: 0 },
    ];

    let edge = '', grit = '';

    const hypot = (x, y) => Math.hypot(x, y) || 1;
    const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

    const segmentT = (a, b) => {
      const ax = a.b.x - a.a.x, ay = a.b.y - a.a.y;
      const bx = b.b.x - b.a.x, by = b.b.y - b.a.y;
      const den = ax * by - ay * bx;
      if (Math.abs(den) < 1e-8) return null;
      const dx = b.a.x - a.a.x, dy = b.a.y - a.a.y;
      const t = (dx * by - dy * bx) / den;
      const u = (dx * ay - dy * ax) / den;
      if (t < 0 || t > 1 || u < 0 || u > 1) return null;
      return t;
    };

    const clipStroke = (stroke, neighbors) => {
      let t0 = 0, t1 = 1, hit = false;
      for (let n = 0; n < neighbors.length; n++) {
        const strokes = neighbors[n].strokes;
        for (let s = 0; s < strokes.length; s++) {
          const t = segmentT(stroke, strokes[s]);
          if (t == null) continue;
          hit = true;
          if (t < 0.5) t0 = Math.max(t0, t);
          else t1 = Math.min(t1, t);
        }
      }
      if (!hit) return stroke;
      if (t1 - t0 < 0.2) return null;
      const a = {
        x: stroke.a.x + (stroke.b.x - stroke.a.x) * t0,
        y: stroke.a.y + (stroke.b.y - stroke.a.y) * t0,
      };
      const b = {
        x: stroke.a.x + (stroke.b.x - stroke.a.x) * t1,
        y: stroke.a.y + (stroke.b.y - stroke.a.y) * t1,
      };
      if (dist(a, b) < MIN_STROKE) return null;
      return { a, b };
    };

    const pickAngle = (x, y, neighbors) => {
      for (let k = 0; k < 22; k++) {
        const cand = inkNoise(x, y, 71 + k * 11) * Math.PI;
        let ok = true;
        for (let n = 0; n < neighbors.length; n++) {
          const c = Math.abs(Math.cos(cand - neighbors[n].angle));
          if (c > 0.88 || c < 0.12) { ok = false; break; }
        }
        if (ok) return cand;
      }
      if (neighbors[0]) return neighbors[0].angle + 0.7 + (inkNoise(x, y, 99) - 0.5) * 0.24;
      return inkNoise(x, y, 71) * Math.PI;
    };

    // Poisson samples in the rock band just outside the floor.
    const samples = [];
    const grid = new Map();
    const cell = POISSON_R / Math.SQRT2;
    const gkey = (x, y) => `${x},${y}`;
    const tooClose = (p) => {
      const gx = Math.floor(p.x / cell);
      const gy = Math.floor(p.y / cell);
      for (let oy = -2; oy <= 2; oy++) {
        for (let ox = -2; ox <= 2; ox++) {
          const list = grid.get(gkey(gx + ox, gy + oy));
          if (!list) continue;
          for (let i = 0; i < list.length; i++) {
            if (dist(p, list[i]) < POISSON_R) return true;
          }
        }
      }
      return false;
    };
    const addSample = (p) => {
      samples.push(p);
      const k = gkey(Math.floor(p.x / cell), Math.floor(p.y / cell));
      const list = grid.get(k);
      if (list) list.push(p);
      else grid.set(k, [p]);
    };

    const layers = Math.max(3, Math.round(HATCH_BAND / (POISSON_R * 0.62)));

    rects.forEach((r) => {
      sides(r).forEach((sd) => {
        const dx = sd.x1 - sd.x0, dy = sd.y1 - sd.y0;
        const len = hypot(dx, dy);
        if (len < 1e-6) return;
        const ux = dx / len, uy = dy / len;
        const steps = Math.max(1, Math.round(len / INK_STEP));
        let runFrom = null, prevT = 0;
        for (let i = 0; i <= steps; i++) {
          const t = (i / steps) * len;
          const x = sd.x0 + ux * t, y = sd.y0 + uy * t;
          const out = isRock(x + sd.nx * 0.12, y + sd.ny * 0.12);
          if (out) {
            if (runFrom === null) runFrom = t;
            prevT = t;
          }
          if ((!out || i === steps) && runFrom !== null) {
            const ax = sd.x0 + ux * runFrom, ay = sd.y0 + uy * runFrom;
            const bx = sd.x0 + ux * prevT, by = sd.y0 + uy * prevT;
            if (prevT - runFrom > 1e-6) {
              // A hair of wall wobble so the ink edge is not a plotter line.
              const n = 4;
              let d = `M ${round3(ax)} ${round3(ay)} `;
              for (let s = 1; s <= n; s++) {
                const tt = runFrom + (prevT - runFrom) * (s / n);
                const px = sd.x0 + ux * tt, py = sd.y0 + uy * tt;
                const j = (inkNoise(px, py, 7) - 0.5) * 0.16;
                d += `L ${round3(px + sd.nx * j)} ${round3(py + sd.ny * j)} `;
              }
              edge += d;
            }
            runFrom = null;
          }
          if (!out) continue;

          let rockDepth = HATCH_BAND;
          for (let d = 0.4; d <= HATCH_BAND; d += 0.4) {
            if (inside(x + sd.nx * d, y + sd.ny * d)) { rockDepth = d; break; }
          }
          const reach = rockDepth >= HATCH_BAND ? HATCH_BAND : rockDepth * 0.5;

          for (let layer = 0; layer < layers; layer++) {
            const along = (inkNoise(x, y, 41 + layer * 13) - 0.5) * POISSON_R * 0.8;
            const outD = HATCH_LIFT + layer * (reach / layers)
              + (inkNoise(x, y, 43 + layer * 17) - 0.5) * 0.24;
            if (outD > reach) continue;
            const q = {
              x: x + sd.nx * outD + ux * along,
              y: y + sd.ny * outD + uy * along,
              d: outD,
            };
            if (!isRock(q.x, q.y)) continue;
            if (tooClose(q)) continue;
            addSample(q);
          }

          if (inkNoise(x, y, 13) > 0.972) {
            const d = 0.35 + inkNoise(x, y, 17) * 1.2;
            const rr = 0.13 + inkNoise(x, y, 19) * 0.12;
            const gx = x + sd.nx * d + uy * (inkNoise(x, y, 23) - 0.5) * 0.8;
            const gy = y + sd.ny * d - ux * (inkNoise(x, y, 23) - 0.5) * 0.8;
            grit += `M ${round3(gx - rr)} ${round3(gy)} a ${round3(rr)} ${round3(rr)} 0 1 0 ${round3(rr * 2)} 0 `
                  + `a ${round3(rr)} ${round3(rr)} 0 1 0 ${round3(-rr * 2)} 0 `;
          }
        }
      });
    });

    samples.sort((a, b) => a.d - b.d);

    const clusters = [];
    const hashCell = POISSON_R;
    const buckets = new Map();
    const bkey = (x, y) => `${x},${y}`;
    const neighborsOf = (p) => {
      const gx = Math.floor(p.x / hashCell);
      const gy = Math.floor(p.y / hashCell);
      const reach = Math.ceil(NEIGHBOR_R / hashCell) + 1;
      const out = [];
      for (let oy = -reach; oy <= reach; oy++) {
        for (let ox = -reach; ox <= reach; ox++) {
          const list = buckets.get(bkey(gx + ox, gy + oy));
          if (!list) continue;
          for (let j = 0; j < list.length; j++) {
            const other = clusters[list[j]];
            if (dist(p, other.origin) <= NEIGHBOR_R) out.push(other);
          }
        }
      }
      return out;
    };

    let hatch = '';
    for (let i = 0; i < samples.length; i++) {
      const p = samples[i];
      const nearby = neighborsOf(p);
      const angle = pickAngle(p.x, p.y, nearby);
      const cluster = { origin: p, angle, strokes: [] };
      const dx = Math.cos(angle), dy = Math.sin(angle);
      for (let s = 0; s < 3; s++) {
        const offset = (s - 1) * STROKE_SPACING;
        const variation = (inkNoise(p.x, p.y, 53 + s * 23) - 0.5) * 0.2 * STROKE_LEN;
        const half = STROKE_LEN / 2 + variation;
        const jitter = (inkNoise(p.x, p.y, 59 + s * 29) - 0.5) * 0.08;
        const sdx = Math.cos(angle + jitter), sdy = Math.sin(angle + jitter);
        const raw = {
          a: { x: p.x + offset * dy - sdx * half, y: p.y - offset * dx - sdy * half },
          b: { x: p.x + offset * dy + sdx * half, y: p.y - offset * dx + sdy * half },
        };
        const clipped = clipStroke(raw, nearby);
        if (!clipped) continue;
        cluster.strokes.push(clipped);
        hatch += `M ${round3(clipped.a.x)} ${round3(clipped.a.y)} L ${round3(clipped.b.x)} ${round3(clipped.b.y)} `;
      }
      const idx = clusters.length;
      clusters.push(cluster);
      const k = bkey(Math.floor(p.x / hashCell), Math.floor(p.y / hashCell));
      const list = buckets.get(k);
      if (list) list.push(idx);
      else buckets.set(k, [idx]);
    }

    return { edge, hatch, shadow: hatch, grit };
  }

  /**
   * The head of an arrow, at (x2,y2), pointing the way the line runs.
   *
   * A separate <path> rather than a `marker-end`. Markers would size
   * themselves off the stroke, which is the one thing here that must stay
   * hairline — a way out is drawn thin on purpose — so the head would vanish
   * with it. They are also the same class of paint feature as the patterns
   * and masks that froze this chart's rasteriser; a triangle costs nothing
   * and cannot surprise anyone.
   *
   * Returns '' for a zero-length line, which is what a road between a place
   * and itself would be, and what a stub clamped hard against the border can
   * become.
   */
  function arrowHead(x1, y1, x2, y2, len, half, cls) {
    const dx = x2 - x1, dy = y2 - y1;
    const dist = Math.hypot(dx, dy);
    if (!(dist > 1e-6) || !(len > 0)) return '';
    const ux = dx / dist, uy = dy / dist;
    // The perpendicular, for the two back corners.
    const px = -uy, py = ux;
    const bx = x2 - ux * len, by = y2 - uy * len;
    return `<path class="${cls}" d="M ${round3(x2)} ${round3(y2)} `
      + `L ${round3(bx + px * half)} ${round3(by + py * half)} `
      + `L ${round3(bx - px * half)} ${round3(by - py * half)} Z"/>`;
  }

  /** Three decimals is plenty at this scale, and keeps the markup readable. */
  function round3(n) {
    return Math.round(n * 1000) / 1000;
  }

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

    // Sized to the room it stands in, not to the chart.
    //
    // The five ticks are written at a fixed 3.8 by 3.3 units, which was a
    // comfortable third of a room back when a floor was drawn on a 48-column
    // field and the rooms came out around 16 units across. They are nearer 10
    // now, and the same glyph fills them. Rather than pick a new constant that
    // would go stale the next time the field changes — or one tied to the
    // grid, which would swing the other way on this generator's own levels,
    // where a square is nearly three times the size — it is measured off the
    // room: the flight takes a bit under half the room's shorter side. On the
    // old dimensions that lands within a hair of the old size, which is the
    // check that it is the right quantity to hang it on.
    const k = Math.max(0.45, Math.min(1.4, (Math.min(r.w, r.h) * 0.45) / GLYPH_SPAN));

    const ticks = [];
    for (let i = 0; i < 5; i++) {
      const t = up ? i : 4 - i;
      const half = 0.55 + t * 0.28;
      const off = (i - 2) * 0.95;
      // Drawn about the origin so one transform can place and size it.
      ticks.push(wide
        ? `<line x1="${off}" y1="${-half}" x2="${off}" y2="${half}"/>`
        : `<line x1="${-half}" y1="${off}" x2="${half}" y2="${off}"/>`);
    }
    // Which way the flight goes, said in the class as well as in the taper.
    // The two rooms that matter on a floor are the one you came in by and the
    // one that goes deeper, and they are worth telling apart at a glance now
    // that you can only climb out from the first of them.
    return `<g class="wm-stair-ticks ${up ? 'is-up' : 'is-down'}" aria-hidden="true"
      transform="translate(${round3(cx)} ${round3(cy)}) scale(${round3(k)})">${ticks.join('')}</g>`;
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
    // edges of the viewBox.
    //
    // This used to be near-black, so the plate read as a hole in the dark. It
    // is stone-coloured now, because the plate it is copied from draws the
    // rock as a material and not as an absence — the floor is the pale thing
    // and the rock around it is hatched, shadowed and littered. You cannot
    // hatch a void; the hatching has to sit ON something.
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

    // Rooms are drawn only where the party has BEEN. A glimpsed room used to
    // get a dashed outline — you have seen the doorway, so here is the shape
    // behind it — and that was a guess the chart had no business making: the
    // outline is the room's true footprint, so "we have seen a door" was being
    // drawn as "we know how big it is and which way it runs". What you can see
    // from a doorway is the doorway. Go through it and the room is drawn.
    //
    // `shown` is still what the passages use: a passage you have looked down
    // is a passage you have seen the length of.
    const seenRooms = plan.rooms.filter(lit);
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

    // The graph paper, ruled across the whole page.
    //
    // Two things about it were wrong for a long time and are worth recording,
    // because both looked right.
    //
    // It was ruled at HALL_W, on the reasoning that a corridor is ten feet
    // wide so a square the width of a corridor is a ten-foot square. The
    // premise is false: a passage on these floors is drawn 2.4 units wide but
    // is TWO tiles of the level it was generated from, and the tiles are the
    // ten-foot ones. So the ruling was at roughly twenty feet while claiming
    // ten, and — because HALL_W has nothing to do with the level's own grid —
    // room edges landed wherever they happened to. A 7x6-tile room came out
    // 3.40 by 2.73 squares. The pitch now comes from the plan, which measures
    // it through the one projection that exists (see DungeonGen::plan), so a
    // square IS a tile and every wall lands on a line.
    //
    // And it was ruled only inside rooms and passages the party had walked,
    // to avoid graph paper "claiming the floor" of somewhere unvisited. That
    // reasoning came from ruling the FLOOR. Ruling the PAGE says nothing about
    // any room: it is the paper the plan is drawn on, and it runs under the
    // rock and off the edges exactly as it would on a real plate. Nothing is
    // claimed and the sheet stops stopping at the walls.
    //
    // Plain line elements in one <path>. Nothing clever: no <pattern>, no
    // <mask>. Both were tried and both, repainted on scroll, froze Chrome's
    // renderer solid — a pattern-stroked polyline is apparently a paint the
    // rasteriser will sit down and think about. Two hundred hairlines in one
    // <path> is nothing.
    let rules = '';
    const ruling = plan.grid;
    if (PLAN_RULED && ruling && ruling.dx > 0.01 && ruling.dy > 0.01) {
      // The page, padded: the ground rect runs to these bounds and the ruling
      // has to reach them or the paper stops before the plate does.
      const x0 = -PAD_X, x1 = VIEW_W + PAD_X;
      const y0 = -PAD_TOP, y1 = VIEW_H + PAD_BOTTOM;
      // Anchored on the grid's own origin and stepped out in both directions,
      // so the lines sit on tile boundaries rather than merely near them.
      // Stepped by INDEX rather than by adding the pitch each time round.
      // Adding accumulates the float error along with the position, and a
      // hundred additions of a rounded pitch is the same drift again by the
      // far edge of the page.
      const from = (origin, pitch, lo) => Math.ceil((lo - origin) / pitch);
      const upto = (origin, pitch, hi) => Math.floor((hi - origin) / pitch);
      for (let k = from(ruling.x, ruling.dx, x0); k <= upto(ruling.x, ruling.dx, x1); k++) {
        const x = ruling.x + k * ruling.dx;
        rules += `M ${round3(x)} ${round3(y0)} V ${round3(y1)} `;
      }
      for (let k = from(ruling.y, ruling.dy, y0); k <= upto(ruling.y, ruling.dy, y1); k++) {
        const y = ruling.y + k * ruling.dy;
        rules += `M ${round3(x0)} ${round3(y)} H ${round3(x1)} `;
      }
    }
    const grid = rules ? `<path class="wm-grid-line" d="${rules}"/>` : '';

    // The rock's edge, worked from the union of everything the party has
    // actually walked. Glimpsed shapes are left out on purpose: a doorway seen
    // from a passage tells you a room is there, not where its walls run, and
    // hatching it would be the chart claiming to know. They keep the dashed
    // outline the fog rules give them.
    const ink = inkRock(floorRects(plan, lit));
    // `shadow` is the same path as `hatch` — a fat grey stroke of
    // `.wm-rock-shadow` (try stroke #d4d0c7, width ~1.05, round caps) sits
    // under `.wm-hatch` (ink #1a1612, width ~0.18, butt caps).
    const rockwork = `
      <path class="wm-rock-shadow" d="${ink.shadow}"/>
      <path class="wm-hatch" d="${ink.hatch}"/>
      <path class="wm-rock-edge" d="${ink.edge}"/>
      <path class="wm-rubble" d="${ink.grit}"/>`;

    // The stair down, in ticks that shorten as they go — a thing you find by
    // walking into the room that holds it.
    //
    // The way OUT is not drawn as a flight at all. It was, and the two came
    // out very nearly identical: two dashed gold rooms, each with a taper of
    // ticks in it, told apart only by which end of the taper was longer. That
    // is a distinction you have to be looking for, and the way out is the one
    // mark on a floor plan you may be looking for in a hurry — you can only
    // climb out from that room now, so a party deep in a level and running out
    // of hit points has to be able to pick it off the chart at a glance.
    //
    // So it takes the mark the chart already uses for leaving: an arrow in a
    // disc, the same one on the end of the stub that runs off the border. One
    // vocabulary — a disc with an arrow in it means daylight, wherever it is
    // drawn — and nothing to compare tapers over.
    const stairPin = ruling && ruling.dx > 0.01
      ? Math.min(ruling.dx, ruling.dy) / 4
      : 1;
    const stairs = plan.rooms.filter(lit).map((r) => {
      if (r.role === 'stair') return stairTicks(r, false);
      if (r.role !== 'entrance') return '';
      // In the corner, not the middle. The middle already belongs to the
      // room's own pin, and at one square each the two discs simply sat on top
      // of one another. Inset by about a square, and clamped so it stays
      // inside a small room rather than riding out onto the wall.
      const sq = ruling && ruling.dx > 0.01 ? Math.min(ruling.dx, ruling.dy) : 2.4;
      const inset = Math.min(sq * 0.85, r.w * 0.28, r.h * 0.28);
      const cx = r.x + inset;
      const cy = r.y + inset;
      return `<g class="wm-waypin" aria-hidden="true"
          transform="translate(${round3(cx)} ${round3(cy)}) scale(${round3(stairPin)})">
        <circle class="wm-waypin-disc" cx="0" cy="0" r="2"/>
        <text class="wm-waypin-mark" dy="0.78">↑</text>
      </g>`;
    }).join('');

    // One glyph per threshold, and each doorway has two — at_a is the wall
    // the corridor leaves, at is the wall it arrives at. Orientation comes
    // from the segment actually meeting that wall, so a dogleg's two glyphs
    // can face different ways and both sit square across their own door.
    //
    // A door is also the way THROUGH it. Each threshold has a place on either
    // side — a room and the passage that meets it — and if the party is
    // standing on one of them, clicking the door is a step onto the other.
    // Which side is the target therefore depends on where you are: the same
    // glyph leads into the room from the passage and into the passage from the
    // room. A threshold with the party on neither side is drawn and left
    // inert; stepping through a door two rooms away is not a move.
    // An open threshold and an arch are drawn with no glyph at all — there is
    // nothing hanging in the gap to draw — but they are still a way through,
    // and on a floor generated by DungeonGen rather than by the map service
    // they are SIXTY PER CENT of the thresholds (see DungeonGen::door). They
    // get the same invisible pad the glyphs get, or those rooms would have no
    // way in that a pointer could find.
    const doors = runs.map((c) => {
      if (!c.door) return '';
      const pts = c.points;
      const ends = [
        { p: c.at_a || pts[0], n: pts[1], room: c.from },
        { p: c.at || pts[pts.length - 1], n: pts[pts.length - 2], room: c.to },
      ];
      return ends.map(({ p, n, room }) => {
        const glyph = doorGlyph(c.door, p[0], p[1], Math.abs(n[0] - p[0]) < 1e-6);
        // The two places this threshold joins, and the one that is not here.
        const through = hereId != null && room != null && c.location_id != null
          ? (hereId === room ? c.location_id : (hereId === c.location_id ? room : null))
          : null;
        // Nothing drawn and nowhere to go: an open threshold the party is not
        // standing beside is not worth an element.
        if (through == null) return glyph ? `<g class="wm-doorway">${glyph}</g>` : '';
        const name = c.door === 'locked' ? 'Try the locked door'
          : c.door === 'trapped' ? 'Through the door — you know it is trapped'
          : c.door === 'stuck' ? 'Force the stuck door'
          : c.door === 'portcullis' ? 'Through the portcullis'
          : (c.door === 'open' || c.door === 'arch') ? 'Through the opening'
          : 'Through the door';
        // A pad, because the glyph itself is a hairline box a door's width
        // across and the pointer needs something to land on.
        // `data-door-to` rather than `data-map-node`: a door is no longer a
        // click that travels. It opens the menu of things a party can do at a
        // threshold — look it over, disarm what they found, pick, force,
        // brace — and walking through is the first item on it. game.js turns
        // the place beyond into the exit that leads there.
        return `<g class="wm-doorway is-through" data-door-to="${through}"
                   role="button" tabindex="0" aria-label="${esc(name)}"
                   data-room-pin data-room-name="${esc(name)}">
          <circle class="wm-door-hit" cx="${round3(p[0])}" cy="${round3(p[1])}" r="${round3(HALL_W * 0.7)}"/>
          ${glyph}
        </g>`;
      }).join('');
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

    // The furnishing, drawn in the corner it stands in.
    //
    // ONE GLYPH FOR ALL SIX KINDS. A chest, a strongbox, a barrel, a crate, a
    // sarcophagus and a cabinet are six words for "there is something in here
    // that shuts", and six silhouettes at two millimetres across would be six
    // things to squint at to learn nothing — the name is in the title and in
    // the room's own paragraph, which is where a name belongs. What the mark
    // has to say is that the room has one.
    //
    // The far corner, matching the raster: DungeonGen::tiles() stands the prop
    // at the room's own max-x/max-y tile, and a chart that put it anywhere
    // else would be a second opinion about where the thing is. Inset by about
    // a square and clamped to the room, the same way the entrance pin is.
    const propMarks = plan.rooms.filter(lit).map((r) => {
      if (!r.furnishing) return '';
      const sq = ruling && ruling.dx > 0.01 ? Math.min(ruling.dx, ruling.dy) : 2.4;
      const inset = Math.min(sq * 0.85, r.w * 0.28, r.h * 0.28);
      const cx = r.x + r.w - inset;
      const cy = r.y + r.h - inset;
      const half = Math.min(sq * 0.34, r.w * 0.16, r.h * 0.16);
      // Shut, the lid lies across the box. Open, it stands off the back of it —
      // the same line, moved out past the edge, which is what an open lid looks
      // like from above and needs no second glyph to say.
      const open = !!r.furnishing_open;
      const lid = open ? cy - half * 1.42 : cy - half * 0.34;
      const span = open ? half * 1.1 : half;
      return `<g class="wm-prop${open ? ' is-open' : ''}" aria-hidden="true">
        <rect class="wm-prop-body" x="${round3(cx - half)}" y="${round3(cy - half)}"
              width="${round3(half * 2)}" height="${round3(half * 2)}" rx="${round3(half * 0.22)}"/>
        <path class="wm-prop-lid" d="M ${round3(cx - span)} ${round3(lid)} H ${round3(cx + span)}"/>
        <title>${esc(String(r.furnishing))}${open ? ', open' : ''}</title>
      </g>`;
    }).join('');

    // Order matters: the rockwork goes down on the bare rock, and the floors
    // and rooms are painted over the top of it. That is what lets a hatch
    // stroke start a hair inside the wall — the overshoot is covered by the
    // floor that lands on it, so every stroke meets the edge line cleanly
    // instead of floating a gap away from it.
    return `<g class="wm-plan">${rock}${rockwork}${walls}${floors}${rooms}${grid}${stairs}${doors}${propMarks}${trapMarks}</g>`;
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
  function node(n, ways, quests, marks, folk, floorplan, pinScale) {
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
    // Where you are already standing gets no name written across it.
    //
    // It is the one label that was never a control — the current place is not
    // clickable, because travelling to where you already are is an error the
    // server would rightly refuse — so it was a caption and nothing more. And
    // it captioned something the screen says twice over: the bar above the
    // chart carries the region, the heading carries "<place>, <region>", and
    // the party's own faces are drawn on the spot. What it did do was lie
    // across the middle of the map art, which on a region with one location in
    // it is most of the picture.
    //
    // The group stays — the tooltip still names the place, and the party marks
    // are rendered inside it.
    // On a floor plan the name is a marker you ask, not a caption you read.
    //
    // A region chart is mostly empty parchment and a place-name sitting on it
    // is the whole of what is drawn — there is nothing for it to cover. A floor
    // plan is the opposite: the rooms ARE drawn, at their real shapes, and a
    // level of them is a dozen names laid across a dozen boxes, each one wider
    // than the room it belongs to and overlapping its neighbours. The chart
    // stopped being a map of the level and became a list of names arranged
    // roughly like one.
    //
    // So down here the name goes behind an `i`: small, in the room it belongs
    // to, hovered for the name and clicked to walk there. The rooms are legible
    // again and nothing is lost — the name was never doing any work a tooltip
    // cannot do.
    // On a floor plan, a room nobody has walked into is not drawn at all —
    // not even as the ring that used to stand in for it. The ring was the
    // click target for a room you had seen the door of, and the door is that
    // target now; leaving the ring as well marked the position of a room the
    // chart has just stopped claiming to know anything about. Its group is not
    // rendered either, so there is no invisible hit pad sitting in the rock
    // where the room is going to turn out to be.
    //
    // Region charts keep it. There are no doors out there, and the ring is the
    // documented way an unvisited place stays clickable.
    if (floorplan && unknown) return '';

    const pin = floorplan && !unknown && !n.current;
    const mark = unknown
      ? '<circle class="wm-unwalked" cx="0" cy="0" r="1.5"/>'
      : n.current
        ? ''
        : pin
          ? `<g class="wm-roompin" transform="scale(${round3(pinScale || 1)})">
               <circle class="wm-roompin-disc" cx="0" cy="0" r="2"/>
               <text class="wm-roompin-i" dy="0.72" aria-hidden="true">i</text>
             </g>`
          : `<text class="wm-label" dy="0.85">${esc(n.name)}</text>`;

    // The hit pad is the width of what is drawn: a name is wide, a pin is not,
    // and a 20-unit pad around a 4-unit marker would swallow the room next door
    // on a plan where the rooms are small.
    const pad = unknown ? 'x="-3.2" width="6.4"'
      : pin ? 'x="-3" width="6"'
      : 'x="-10" width="20"';

    // The tooltip hook goes on the node, not on the drawn pin: the pin is
    // `pointer-events: none` and the hit rect is its sibling, so a hook on the
    // pin itself is one the mouse can never reach.
    //
    // A named node keeps `<title>`, which is both its accessible name and the
    // browser's own tooltip. A pinned one takes `aria-label` instead — same
    // name for a screen reader, but without the native tooltip arriving a
    // second and a half after the styled one to say the same thing twice.
    const asks = pin ? ` data-room-pin data-room-name="${esc(n.name)}"` : '';
    const aria = pin ? ` aria-label="${esc(title)}"` : '';

    return `<g class="${cls}" transform="translate(${n.x} ${n.y})"
      ${n.current ? '' : `data-map-node="${n.id}" role="button" tabindex="0"`}${asks}${aria}>
      ${pin ? '' : `<title>${esc(title)}</title>`}
      <rect class="wm-hit" ${pad} y="-4" height="8" rx="1"/>
      ${mark}
      ${quest ? `<g data-quest-pin transform="scale(${round3(pinScale || 1)})"
          data-quest-info="${esc((quest.lines.length ? quest.lines : ['Something wants you here']).join('\n'))}">
        <circle class="wm-quest-hit" cx="0" cy="-4.2" r="3.4"/>
        <text class="wm-quest${quest.tracked ? ' is-tracked' : ''}"
          dy="-2.9" aria-hidden="true">?</text>
      </g>` : ''}
      ${n.current ? (folk || '') : ''}
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
  function neighborArrow(nb, byId, ways, hereId, floorplan, pinScale, headLen, headHalf) {
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

    // On a floor plan the caption becomes a pin, for the reason the room
    // names did — but with a sharper one behind it. A region chart is mostly
    // empty parchment and a road out has room to be captioned; a floor plan is
    // a level drawn at room scale, and this caption was landing straight
    // across whatever room happened to lie between the border and the edge of
    // the chart. On the level it was found on it covered the stair down: the
    // way OUT was written over the way DEEPER, and read as a room in its own
    // right because a name on a plan is what a room looks like.
    //
    // So down here it is an arrow in a disc at the end of the stub, named on
    // hover through the same tip the rooms use. The stub still runs to the
    // border, so the direction is still drawn — only the words move.
    const pinned = floorplan && !!caption;
    const label = pinned
      ? `<g class="wm-exitpin" transform="translate(${tx + dx * 2.4} ${ty}) scale(${round3(pinScale || 1)})">
           <circle class="wm-exitpin-hit" cx="0" cy="0" r="3.2"/>
           <circle class="wm-exitpin-disc" cx="0" cy="0" r="2"/>
           <text class="wm-exitpin-mark" dy="0.78" aria-hidden="true">↑</text>
         </g>`
      : `<text class="wm-out-label" x="${tx + dx * 2.4}" y="${ty + 0.9}"
            text-anchor="${anchor}">${esc(caption)}</text>`;

    // A pinned exit takes its name from the tip rather than from <title>, so
    // the browser's own tooltip does not turn up a second and a half later to
    // say the same thing again. Unpinned, <title> is still the accessible name.
    const tip = pinned ? ` data-room-pin data-room-name="${esc(title)}"` : '';

    return `<g class="${cls}"${clickable
        ? ` data-map-node="${nb.to_location_id}" role="button" tabindex="0"`
        : ' aria-hidden="true"'}${tip}${pinned ? ` aria-label="${esc(title)}"` : ''}>
      ${pinned ? '' : `<title>${esc(title)}</title>`}
      <line class="wm-edge is-out${isWayFromHere ? ' is-way' : ''}${way && way.locked ? ' is-locked' : ''}"
            x1="${from.x}" y1="${from.y}" x2="${tx}" y2="${ty}"/>
      ${isWayFromHere
        ? arrowHead(from.x, from.y, tx, ty, headLen, headHalf,
            `wm-edge-head is-way${way && way.locked ? ' is-locked' : ''}`)
        : ''}
      ${label}
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
  /**
   * How big a ten-foot square wants to be, in CSS pixels, when a floor plan
   * first opens.
   *
   * A token is one square (see the scale in svg()), and a square that opens at
   * ten pixels is a face you cannot recognise. The old fixed open zoom was
   * chosen when a token was two squares across and could afford it; sizing the
   * token honestly means opening closer, which is the trade — less of the
   * level on screen, but what is on it can be read. Twenty-six is about the
   * smallest a cropped portrait stays a person at.
   */
  const PLAN_SQUARE_PX = 26;

  const BASE = {
    x: -PAD_X,
    y: -PAD_TOP,
    w: VIEW_W + PAD_X * 2,
    h: VIEW_H + PAD_TOP + PAD_BOTTOM,
  };

  /**
   * How close a floor plan should open, from the size of its own squares.
   *
   * Zero for anything that is not a plan, and for a plan measured before it
   * has been laid out — a width of nothing would divide into an absurd zoom,
   * and the caller's fallback is the better answer than a guess.
   */
  function planOpenZoom(svgEl) {
    const pitch = parseFloat(svgEl.getAttribute('data-plan-pitch') || '');
    if (!(pitch > 0.01)) return 0;
    const width = svgEl.getBoundingClientRect().width;
    if (!(width > 1)) return 0;
    const pxPerUnit = width / BASE.w;          // at the fitted zoom
    const z = PLAN_SQUARE_PX / (pitch * pxPerUnit);
    return Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, z));
  }

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
    // A floor plan opens to its own grid rather than to the caller's guess.
    // The caller's number stays the fallback, and is still what a region chart
    // uses — a region has no squares to open to.
    const opens = planOpenZoom(svgEl) || openZoom || ZOOM_OPEN;
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
      canPan: (e) => (e.button === 0
                      && !e.target.closest('[data-map-node]')
                      && !e.target.closest('[data-door-to]')
                      && !e.target.closest('[data-npc]'))
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