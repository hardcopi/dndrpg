/**
 * The dungeon, looked at from inside it.
 *
 * A forward-facing view of a generated floor, in the manner the Gold Box games
 * drew one: flat-shaded wall polygons darkening with distance, a step at a
 * time, turn in place. It draws the raster DelveEngine ships in
 * `region_map.floorplan.tiles` and it decides nothing about the level — not
 * what is walkable, not what is hidden, not what a door is. Same rule as
 * ui-combat.js and the board: the engine ships a derived block and the client
 * paints it. A second copy of the level's shape in JavaScript is a copy that
 * disagrees with the one the exits are built from.
 *
 * WHAT IS AND IS NOT STATE. The cursor — which tile the party is standing on
 * and which way they are looking — is VIEW state, like the chart's pan and
 * zoom. `characters.current_location_id` is the truth, and it is a whole room
 * or a whole passage. So a step within one location is a redraw and nothing
 * else, a step across a threshold is the ordinary one-hop `location/travel`
 * the chart has always issued, and after any refresh the cursor is reconciled
 * against whatever the server says by `place()`. Nothing here writes to the
 * server and nothing here believes itself over it.
 *
 * NO DOM, NO GAME. Everything below takes a raster and a cursor and returns a
 * string or a plain object, which is what lets tools/floorplan_preview.php
 * exist for the chart and would let the same bench exist for this.
 */
(function () {
  /**
   * The picture's own field.
   *
   * The proportions are ui-map.js's, not a taste: VIEW_W + 2*PAD_X by
   * VIEW_H + PAD_TOP + PAD_BOTTOM is 118 by 87, which is the shape of the
   * chart this view flips to and from. Matched so that the panel does not
   * change height when it does — a picture that jumps an inch every time you
   * press V reads as the page reloading rather than as the same floor seen
   * another way. It also means the view fits its box exactly, so nothing is
   * letterboxed and nothing is cropped off the sides.
   */
  const VIEW_W = 320;
  const VIEW_H = 236;
  const CX = VIEW_W / 2;
  const CY = VIEW_H / 2;

  /**
   * Focal length, in the same units a tile is one of.
   *
   * Sets the field of view: 190 against a half-width of 160 is about eighty
   * degrees across, and sixty down. It was 150, which is ninety-three across
   * — a wide-angle lens, and wide angles shrink things fast. A ten-foot wall
   * twenty feet off came out a third of the frame high with dead ceiling above
   * it and dead floor below, so a chamber read as a letterbox rather than as
   * somewhere with a roof on. Narrower is also what the games this is copying
   * did, because they were drawing a room and not surveying one.
   */
  const F = 190;

  /** Half the height of a wall. Half a tile, so a passage is square in section. */
  const H = 0.5;

  /** How far ahead is drawn, and how far to either side. */
  const DEPTH = 5;
  const SPAN = 3;

  /**
   * Nothing nearer than this is drawn.
   *
   * The eye sits at the back edge of the party's own tile, so the side walls of
   * that tile start at z = 0 and project to infinity. Every quad is axis
   * aligned, so clipping is one clamp on the near end of a range rather than
   * any actual geometry.
   */
  const NEAR = 0.16;

  /** N, E, S, W — the same numbering the raster's door faces use. */
  const DX = [0, 1, 0, -1];
  const DY = [-1, 0, 1, 0];

  /** Rock, in the tile layer. Must match DelveEngine::TILE_ROCK. */
  const ROCK = ' ';

  /** Which door kinds you can see through. The rest have a leaf in them. */
  const OPENINGS = { open: true, arch: true, portcullis: true };

  // =======================================================================
  // Reading the raster
  // =======================================================================

  /**
   * Faces and stairs, in a map, so drawing a cell is lookups and not scans.
   *
   * Rebuilt whenever the raster object changes identity — which is on every
   * refresh, because the payload is parsed fresh — and cached on the raster
   * itself so a turn on the spot does not rebuild it.
   */
  function index(tiles) {
    if (tiles._fp) return tiles._fp;
    const doors = new Map();
    const walls = new Set();
    const stairs = new Map();
    (tiles.doors || []).forEach((d) => doors.set(d.t * 4 + d.d, d));
    const props = new Map();
    (tiles.props || []).forEach((p) => props.set(p.t, p));
    const figs = new Map();
    (tiles.figs || []).forEach((f) => figs.set(f.t, f));
    (tiles.walls || []).forEach((w) => walls.add(w.t * 4 + w.d));
    (tiles.stairs || []).forEach((s) => stairs.set(s.t, s.d));
    const fp = { doors, walls, stairs, props, figs };
    try { Object.defineProperty(tiles, '_fp', { value: fp, enumerable: false }); } catch (e) { tiles._fp = fp; }
    return fp;
  }

  const tileOf = (tiles, x, y) => y * tiles.w + x;
  const inside = (tiles, x, y) => x >= 0 && y >= 0 && x < tiles.w && y < tiles.h;

  /** The character in the tile layer, or rock for anywhere off the raster. */
  function glyph(tiles, x, y) {
    if (!inside(tiles, x, y)) return ROCK;
    const row = tiles.rows[y];
    return row ? row.charAt(x) || ROCK : ROCK;
  }

  const isFloor = (tiles, x, y) => glyph(tiles, x, y) !== ROCK;

  /** Which location owns a tile, or null where there is no floor shipped. */
  function ownerAt(tiles, x, y) {
    const ch = glyph(tiles, x, y);
    if (ch === ROCK) return null;
    const id = (tiles.locs || {})[ch];
    return id === undefined ? null : id;
  }

  /**
   * What is on the face between a tile and its neighbour in `dir`.
   *
   * A doorway wins over everything, because a threshold is shipped even when
   * what lies past it is not — that is how a party walks into a room they have
   * never seen. Then a partition, which is the raster's way of saying two
   * passages squeezed past each other with no rock left between them. Then the
   * plain question of whether there is floor on the other side.
   */
  function faceOf(tiles, x, y, dir) {
    const idx = index(tiles);
    const key = tileOf(tiles, x, y) * 4 + dir;
    const door = idx.doors.get(key);
    if (door) return { kind: door.k || 'door', to: door.to, door: true };
    if (idx.walls.has(key)) return { kind: 'wall' };
    return isFloor(tiles, x + DX[dir], y + DY[dir]) ? { kind: 'open' } : { kind: 'wall' };
  }

  // =======================================================================
  // Where the party is, and how they move
  // =======================================================================

  /**
   * Put the cursor down in a location, keeping the facing if it still means
   * anything.
   *
   * Called on arrival, after an interrupted journey, after a descent, and on a
   * reload — every case where the server has an opinion about where the party
   * is and the cursor may not. The spine is the middle of a chamber or the
   * middle of a run; `spines` is keyed by location id precisely so that this
   * needs no geometry.
   */
  function place(tiles, locationId, facing) {
    const tile = (tiles.spines || {})[locationId];
    if (tile === undefined) return null;
    const cursor = { x: tile % tiles.w, y: Math.floor(tile / tiles.w), facing: facing | 0 };
    // Face somewhere there is a way out, so arriving never stares at rock.
    for (let n = 0; n < 4; n++) {
      const d = (cursor.facing + n) % 4;
      if (faceOf(tiles, cursor.x, cursor.y, d).kind !== 'wall') {
        cursor.facing = d;
        break;
      }
    }
    return cursor;
  }

  /** Whether the cursor is still standing where the server says the party is. */
  function agrees(tiles, cursor, locationId) {
    return !!cursor && ownerAt(tiles, cursor.x, cursor.y) === locationId;
  }

  const turn = (cursor, by) => ({ x: cursor.x, y: cursor.y, facing: (cursor.facing + by + 4) % 4 });

  /**
   * What one step means.
   *
   * Three answers and they are the whole movement model: a wall, a step inside
   * the place you are already in, or a threshold — which is a request to the
   * server and nothing this file may decide the outcome of. A locked door, a
   * trap, a wandering monster and a refusal all belong to `location/travel`,
   * exactly as they do when the chart is driving.
   *
   * `heading` is which way the step goes and defaults to the way the party is
   * looking. Backing up passes the opposite, and the RESULT KEEPS `cursor.facing`
   * — that is the whole of what makes it a step backwards rather than a turn
   * and a walk. Everything else is identical, including the threshold: a party
   * that reverses through a doorway has crossed it exactly as if they had
   * walked through forwards, and the server is asked in the same words.
   */
  function step(tiles, cursor, hereId, heading) {
    const dir = heading === undefined || heading === null ? cursor.facing : heading;
    const face = faceOf(tiles, cursor.x, cursor.y, dir);
    if (face.kind === 'wall') return { blocked: true };

    const nx = cursor.x + DX[dir];
    const ny = cursor.y + DY[dir];
    const to = { x: nx, y: ny, facing: cursor.facing };
    const there = ownerAt(tiles, nx, ny);

    if (there !== null && there === hereId) return { cursor: to };

    // Across a threshold. The door carries the location beyond it, which is
    // the case that matters: the tiles on the far side may not be shipped yet.
    const travel = face.door && face.to != null ? face.to : there;
    if (travel == null) return { blocked: true };
    return { cursor: to, travel: travel };
  }

  // =======================================================================
  // Drawing
  //
  // Flat quads, painted back to front. Every surface is axis aligned in the
  // party's own frame, so a projection is two divides and a clip is one clamp;
  // there is no clipper, no z-buffer and no matrix anywhere in here.
  // =======================================================================

  const px = (n) => Math.round(n * 100) / 100;

  /** A point in the party's frame — x right, y up, z forward — to the picture. */
  function proj(x, y, z) {
    return [px(CX + (F * x) / z), px(CY - (F * y) / z)];
  }

  const poly = (pts) => pts.map((p) => p[0] + ',' + p[1]).join(' ');

  /**
   * Which surfaces are textured, and HOW MANY TEXTURE SHEETS COVER ONE DUNGEON
   * TILE. One means a wall face shows the whole brick sheet once, which is
   * about ten courses of stone across a ten-foot wall.
   *
   * Expressed as a ratio rather than as a size in picture units, which is what
   * it was first and what made it wrong: 26 units against a face that projects
   * to F across at the near band is 7.3 repeats, and 7.3 repeats of a
   * ten-course sheet is seventy courses of brick on one wall. At that size the
   * stones are a couple of units each, they average out, and the whole thing
   * reads as a pale wash rather than as masonry — which is exactly how it
   * looked. A ratio cannot go wrong that way: the projection is applied to it
   * in defs() rather than guessed at here.
   *
   * The roof is not here on purpose: it is nearly black at every band, and a
   * texture nobody can see the result of is a request nobody should pay for.
   */
  const GRAIN = { wall: 1, side: 1, floor: 1 };

  /**
   * Which swatch a surface is made of.
   *
   * `side` is the same brick as `wall`, because it IS the same wall — the only
   * difference is which way it faces. Giving it its own texture is what made
   * a corridor's left and right walls come out grey while the one ahead was
   * brown: Generic_Rock is 5% saturated and Generic_Brick is 43%, so the
   * masonry appeared to change material with the compass. The plane separation
   * is `--fp-side-*` being a lighter set than `--fp-wall-*`, which is lighting
   * and is the right tool for it.
   */
  const TEXTURE = { wall: 'wall', side: 'wall', floor: 'floor' };

  /**
   * How much of the band's token colour is laid OVER the texture, per depth.
   *
   * This is the distance shading, and it runs the opposite way to the old
   * scheme's fill: near the lamp the scrim is thin and the stone is Synty's,
   * far from it the scrim is nearly solid and the wall is `--fp-wall-5`. The
   * last band is not 1 — a wall that reached the flat colour exactly would pop
   * as it crossed the boundary, and 0.92 keeps a suggestion of stone in the
   * dark.
   */
  const SCRIM = [0.06, 0.2, 0.36, 0.54, 0.72, 0.88];

  /**
   * A surface's paint.
   *
   * THE ONE PLACE A SURFACE'S FILL IS DECIDED, and it is a paint server now
   * rather than a colour — which is what the note that used to be here said it
   * would become, and it has changed nothing else.
   *
   * THE TOKEN STILL DECIDES THE DARK. The pattern is the Synty texture with a
   * scrim over it in the band's own colour, and the scrim thickens with
   * distance — see SCRIM. So the texture says what the stone is and
   * `--fp-wall-5` still says what the far dark looks like, which is the half of
   * the old arrangement worth keeping. Delete the swatches and every face falls
   * back to very nearly the flat colour it had, because the scrim is all that
   * would be left.
   */
  function faceFill(surface, depth) {
    const band = Math.max(0, Math.min(DEPTH, Math.round(depth)));
    if (!GRAIN[surface]) {
      return 'var(--fp-' + surface + '-' + band + ', var(--fp-' + surface + '))';
    }
    return 'url(#fp-' + surface + '-' + band + ')';
  }

  /**
   * The paint servers, one per surface and band.
   *
   * The stones grow toward the viewer, and the rate is the projection's own:
   * a one-tile face at depth z is F/z units across, so a sheet covering one
   * tile is F/z units too. That is not a fudge — it is the same divide proj()
   * does. What it cannot do is foreshorten WITHIN a face, so a wall running
   * away from the viewer carries stones of one size along its whole length
   * instead of converging. At one sheet per tile the eye takes that as masonry
   * courses; at seven it took it as noise.
   */
  function defs(base) {
    const out = [];
    for (const surface of Object.keys(GRAIN)) {
      for (let band = 0; band <= DEPTH; band++) {
        // The projection, applied once, here: F/z units per tile, divided by
        // how many sheets are asked to cover it.
        const size = F / ((band + 1) * GRAIN[surface]);
        const scrim = SCRIM[Math.min(band, SCRIM.length - 1)];
        out.push(
          '<pattern id="fp-' + surface + '-' + band + '" patternUnits="userSpaceOnUse" ' +
            'width="' + px(size) + '" height="' + px(size) + '">' +
            // The texture first, then the dark over it. The bottom rect is the
            // fallback: with no swatch on disk the image draws nothing and this
            // is what shows through, which is the flat cave again.
            '<rect width="' + px(size) + '" height="' + px(size) + '" ' +
              'fill="var(--fp-' + surface + '-' + band + ', var(--fp-' + surface + '))"/>' +
            '<image href="' + base + TEXTURE[surface] + '.png" x="0" y="0" ' +
              'width="' + px(size) + '" height="' + px(size) + '" ' +
              'preserveAspectRatio="none"/>' +
            '<rect width="' + px(size) + '" height="' + px(size) + '" ' +
              'fill="var(--fp-' + surface + '-' + band + ', var(--fp-' + surface + '))" ' +
              'opacity="' + scrim + '"/>' +
          '</pattern>'
        );
      }
    }
    return '<defs>' + out.join('') + '</defs>';
  }

  /** One quad, with the depth it sorts by. */
  function quad(list, z, cls, surface, pts) {
    list.push({
      z: z,
      svg: '<polygon class="fp-' + cls + '" fill="' + faceFill(surface, z) + '" points="' + poly(pts) + '"/>',
    });
  }

  /**
   * A wall across the view, at constant z, spanning x0..x1.
   *
   * `hole` cuts a doorway out of it — as three strips round the gap rather
   * than as a mask, because ui-map.js has SVG masks on record for freezing
   * Chrome and because three rectangles are exact and cost nothing.
   */
  function frontWall(list, x0, x1, z, hole) {
    if (z < NEAR) return;
    const at = (x, y) => proj(x, y, z);
    if (!hole) {
      quad(list, z, 'wall', 'wall', [at(x0, -H), at(x1, -H), at(x1, H), at(x0, H)]);
      return;
    }
    const [h0, h1, top] = hole;
    quad(list, z, 'wall', 'wall', [at(x0, -H), at(h0, -H), at(h0, H), at(x0, H)]);
    quad(list, z, 'wall', 'wall', [at(h1, -H), at(x1, -H), at(x1, H), at(h1, H)]);
    quad(list, z, 'wall', 'wall', [at(h0, top), at(h1, top), at(h1, H), at(h0, H)]);
  }

  /** A wall along the view, at constant x, spanning z0..z1. */
  function sideWall(list, x, z0, z1, hole) {
    const a = Math.max(z0, NEAR);
    const b = Math.max(z1, NEAR);
    if (b <= a) return;
    const at = (z, y) => proj(x, y, z);
    if (!hole) {
      quad(list, a, 'wall is-side', 'side', [at(a, -H), at(b, -H), at(b, H), at(a, H)]);
      return;
    }
    const [h0, h1, top] = [Math.max(hole[0], a), Math.max(hole[1], a), hole[2]];
    quad(list, a, 'wall is-side', 'side', [at(a, -H), at(h0, -H), at(h0, H), at(a, H)]);
    quad(list, h1, 'wall is-side', 'side', [at(h1, -H), at(b, -H), at(b, H), at(h1, H)]);
    quad(list, h0, 'wall is-side', 'side', [at(h0, top), at(h1, top), at(h1, H), at(h0, H)]);
  }

  /** Floor or ceiling under or over one cell. */
  function deck(list, x0, x1, z0, z1, up) {
    const a = Math.max(z0, NEAR);
    const b = Math.max(z1, NEAR);
    if (b <= a) return;
    const y = up ? H : -H;
    const surface = up ? 'roof' : 'floor';
    quad(list, a, surface, surface, [
      proj(x0, y, a), proj(x1, y, a), proj(x1, y, b), proj(x0, y, b),
    ]);
  }

  /**
   * What is drawn in a doorway once the hole is cut.
   *
   * The vocabulary is the chart's, deliberately: a player who has learned that
   * a dashed seam is a way somebody forced and a row of bars is a portcullis
   * has learned it once. `open` and `arch` draw nothing at all — a hole in a
   * wall is its own sign, and marking every one of them would put a symbol on
   * most of the floor and so tell you nothing.
   */
  function leaf(list, kind, corners, z) {
    // A stroke around the hole, so an opening still reads when the room
    // beyond is the same brick as the hall, or is not there yet.
    if (kind === 'open' || kind === 'arch') {
      list.push({
        z: z - 0.001,
        svg: '<polygon class="fp-opening" points="' + poly(corners) + '"/>',
      });
      return;
    }
    const cls = 'fp-leaf is-' + kind;
    if (kind === 'portcullis') {
      const [a, b, c, d] = corners;
      const bars = [];
      for (let i = 1; i < 6; i++) {
        const t = i / 6;
        const lo = [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
        const hi = [d[0] + (c[0] - d[0]) * t, d[1] + (c[1] - d[1]) * t];
        bars.push('<line x1="' + px(lo[0]) + '" y1="' + px(lo[1]) +
                  '" x2="' + px(hi[0]) + '" y2="' + px(hi[1]) + '"/>');
      }
      list.push({ z: z - 0.001, svg: '<g class="' + cls + '">' + bars.join('') + '</g>' });
      return;
    }
    list.push({
      z: z - 0.001,
      svg: '<polygon class="' + cls + '" points="' + poly(corners) + '"/>'
         + ironwork(corners),
    });
  }

  /**
   * The boards and the iron on a door leaf.
   *
   * A leaf was one flat quad, which read as a panel rather than as a door. This
   * is what a door in this setting is made of: three or four vertical boards,
   * two iron straps across them, studs along the straps, and a ring to pull it
   * by. All of it is drawn INSIDE the quad the wall already cut, by bilinear
   * interpolation between its corners — so it leans and foreshortens with the
   * face for free, and a door in the left-hand wall is seen at the same slant
   * as the wall it is set in. That is the one piece of real perspective in this
   * whole view, and it comes from the corners rather than from any matrix.
   *
   * IT DISAPPEARS BEFORE IT BECOMES NOISE. Below about fourteen units across, a
   * door is a few pixels wide and studs on it are dirt on the screen; under
   * that only the plain leaf is drawn, which is still the sign that a way out
   * is there. The strap count drops before the studs do, for the same reason.
   *
   * Colour is left to CSS as everything else here is, so `is-locked` still
   * darkens the whole leaf and `is-stuck` still dashes its outline underneath.
   */
  function ironwork(corners) {
    const [a, b, c, d] = corners;

    // How wide the leaf actually is on screen, taken across its middle rather
    // than along an edge: a door in a side wall has a short near edge and a
    // long far one, and the average is what the eye is judging.
    const wide = (Math.hypot(b[0] - a[0], b[1] - a[1])
                + Math.hypot(c[0] - d[0], c[1] - d[1])) / 2;
    if (wide < 14) return '';

    // Bilinear across the quad: u runs a to b along the foot, v runs foot to
    // head. Straight lines in this space follow the face's own slant.
    const at = (u, v) => [
      px(a[0] + (b[0] - a[0]) * u + (d[0] - a[0]) * v + (a[0] - b[0] + c[0] - d[0]) * u * v),
      px(a[1] + (b[1] - a[1]) * u + (d[1] - a[1]) * v + (a[1] - b[1] + c[1] - d[1]) * u * v),
    ];
    const line = (u0, v0, u1, v1) => {
      const p0 = at(u0, v0);
      const p1 = at(u1, v1);
      return '<line x1="' + p0[0] + '" y1="' + p0[1] +
             '" x2="' + p1[0] + '" y2="' + p1[1] + '"/>';
    };
    const band = (v0, v1) => '<polygon points="' +
      poly([at(0, v0), at(1, v0), at(1, v1), at(0, v1)]) + '"/>';

    const out = [];

    // The boards. Four of them on a wide door, three on a narrow one — a
    // seam every few pixels is a texture, not joinery.
    const seams = wide > 34 ? [0.25, 0.5, 0.75] : [0.34, 0.67];
    out.push('<g class="fp-plank">' +
      seams.map((u) => line(u, 0.02, u, 0.98)).join('') + '</g>');

    // The straps. Two where there is room for two.
    const straps = wide > 22 ? [[0.17, 0.27], [0.65, 0.75]] : [[0.58, 0.7]];
    out.push('<g class="fp-strap">' + straps.map(([v0, v1]) => band(v0, v1)).join('') + '</g>');

    // Studs, and the ring to pull it by. Both are the first things to go.
    if (wide > 30) {
      const r = px(Math.max(0.6, wide * 0.022));
      const studs = [];
      for (const [v0, v1] of straps) {
        const v = (v0 + v1) / 2;
        for (const u of [0.1, 0.35, 0.65, 0.9]) {
          const p = at(u, v);
          studs.push('<circle cx="' + p[0] + '" cy="' + p[1] + '" r="' + r + '"/>');
        }
      }
      const ring = at(0.82, 0.46);
      out.push('<g class="fp-stud">' + studs.join('') + '</g>');
      out.push('<circle class="fp-ring" cx="' + ring[0] + '" cy="' + ring[1] +
               '" r="' + px(Math.max(1, wide * 0.055)) + '"/>');
    }

    return out.join('');
  }

  /**
   * A furnishing, as a box standing on the floor of the cell that holds it.
   *
   * Six faces would be a modelling problem; three are enough for a box seen
   * from outside, and which three depends only on which side of the cell the
   * party is on — front and top always, plus the one side face that is turned
   * toward them. Flat quads sorted by depth like everything else here, so a
   * chest behind a pillar is behind it for free.
   *
   * A LID, NOT A HEIGHT. `top` is a fraction of the cell rather than a real
   * measurement: a sarcophagus and a barrel are the same box at different
   * heights, and the point of the shape is "there is a thing here to open",
   * which reads at any size the cell allows.
   *
   * Dressing is not a box. The view is always face-on, four facings, so a
   * pile of rubble drawn as a crate is a crate. These are Gold Box sprites:
   * a card facing the camera, standing on the floor of the cell. Chests keep
   * the box because they open.
   */
  const PROP_H = { chest: 0.34, strongbox: 0.3, barrel: 0.44, crate: 0.46,
                   sarcophagus: 0.28, cabinet: 0.62 };

  /** A rectangle facing the camera, in the party's frame. */
  function card(xL, y0, xR, y1, z) {
    return '<polygon points="' + poly([
      proj(xL, y0, z), proj(xR, y0, z), proj(xR, y1, z), proj(xL, y1, z),
    ]) + '"/>';
  }

  function oval(cx, cy, z, rx, ry) {
    const c = proj(cx, cy, z);
    const r = proj(cx + rx, cy + ry, z);
    return '<ellipse cx="' + c[0] + '" cy="' + c[1] +
           '" rx="' + px(Math.abs(r[0] - c[0])) +
           '" ry="' + px(Math.abs(r[1] - c[1])) + '"/>';
  }

  /**
   * Set dressing, as a sprite standing in the cell.
   *
   * Constant z, so the card does not recede within itself — that is the whole
   * of the old-school method, and the reason a rubble slide reads as a pile
   * rather than as a box you could open. Drawn in the same ink as the walls.
   */
  function sprite(list, kind, x0, x1, z0, z1) {
    const z = Math.max(z0 + (z1 - z0) * 0.62, NEAR);
    const cx = (x0 + x1) / 2;
    const floor = -H;
    let body = '';
    switch (kind) {
      case 'rubble': {
        const bits = [
          [-0.30, 0.22, 0.14], [-0.08, 0.36, 0.24], [0.22, 0.20, 0.13],
          [0.04, 0.16, 0.10], [-0.18, 0.14, 0.09], [0.30, 0.12, 0.08],
        ];
        body = bits.map(([dx, w, h]) => {
          const jags = [0.2, 0.7, 1, 0.85, 0.4, 0.12];
          const xs = [-1, -0.55, -0.05, 0.5, 0.95, 0.35];
          const pts = xs.map((u, i) => proj(cx + dx + u * (w / 2), floor + jags[i] * h, z));
          return '<polygon points="' + poly(pts) + '"/>';
        }).join('');
        break;
      }
      case 'pool':
        body = oval(cx, floor + 0.02, z, 0.38, 0.05)
             + oval(cx, floor + 0.02, z, 0.26, 0.03);
        break;
      case 'well':
        body = oval(cx, floor + 0.04, z, 0.28, 0.08)
             + oval(cx, floor + 0.05, z, 0.16, 0.045)
             + card(cx - 0.06, floor, cx + 0.06, floor + 0.12, z);
        break;
      case 'pillar':
        body = card(cx - 0.12, floor, cx + 0.12, floor + 0.78, z)
             + card(cx - 0.16, floor + 0.78, cx + 0.16, floor + 0.86, z)
             + card(cx - 0.16, floor, cx + 0.16, floor + 0.08, z);
        break;
      case 'altar':
        body = card(cx - 0.22, floor, cx + 0.22, floor + 0.16, z)
             + card(cx - 0.18, floor + 0.16, cx + 0.18, floor + 0.28, z);
        break;
      case 'bunks':
        body = card(cx - 0.38, floor + 0.04, cx + 0.38, floor + 0.14, z)
             + card(cx - 0.38, floor + 0.18, cx + 0.38, floor + 0.28, z);
        break;
      case 'table':
        body = card(cx - 0.32, floor + 0.18, cx + 0.32, floor + 0.24, z)
             + card(cx - 0.28, floor, cx - 0.22, floor + 0.18, z)
             + card(cx + 0.22, floor, cx + 0.28, floor + 0.18, z);
        break;
      case 'shelves':
        body = card(cx - 0.30, floor, cx + 0.30, floor + 0.52, z)
             + card(cx - 0.28, floor + 0.14, cx + 0.28, floor + 0.18, z)
             + card(cx - 0.28, floor + 0.30, cx + 0.28, floor + 0.34, z);
        break;
      case 'hearth':
        body = card(cx - 0.28, floor, cx + 0.28, floor + 0.36, z)
             + oval(cx, floor + 0.12, z, 0.14, 0.08);
        break;
      case 'brazier':
        body = card(cx - 0.04, floor, cx + 0.04, floor + 0.22, z)
             + oval(cx, floor + 0.28, z, 0.16, 0.08)
             + oval(cx, floor + 0.30, z, 0.10, 0.04);
        break;
      case 'cage': {
        const bars = [];
        for (let i = 0; i < 5; i++) {
          const x = cx - 0.22 + i * 0.11;
          bars.push(card(x - 0.012, floor, x + 0.012, floor + 0.62, z));
        }
        body = card(cx - 0.26, floor, cx + 0.26, floor + 0.04, z)
             + card(cx - 0.26, floor + 0.62, cx + 0.26, floor + 0.68, z)
             + bars.join('');
        break;
      }
      case 'urns':
        body = oval(cx - 0.16, floor + 0.16, z, 0.10, 0.16)
             + oval(cx + 0.16, floor + 0.14, z, 0.09, 0.14)
             + card(cx - 0.20, floor, cx - 0.12, floor + 0.06, z)
             + card(cx + 0.12, floor, cx + 0.20, floor + 0.06, z);
        break;
      case 'winch':
        body = card(cx - 0.04, floor, cx + 0.04, floor + 0.42, z)
             + oval(cx, floor + 0.42, z, 0.16, 0.16)
             + card(cx - 0.18, floor + 0.40, cx + 0.18, floor + 0.44, z);
        break;
      default:
        return false;
    }
    list.push({ z: z - 0.004, svg: '<g class="fp-sprite is-' + kind + '">' + body + '</g>' });
    return true;
  }

  /**
   * A waiting fight, as a battler card standing on the floor.
   *
   * Constant z, same as the dressing sprites: a goblin is a picture facing
   * you, not a box you could walk around. Combat already paints these PNGs
   * on the grid; the corridor uses the same file so the thing you saw down
   * the hall is the thing that then takes a turn. One to three, overlapping
   * — Gold Box never drew a roster in the hallway.
   */
  function figure(list, fig, x0, x1, z0, z1) {
    const key = String(fig.s || '').replace(/[^a-z0-9_]/gi, '');
    if (!key) return;
    // In the party's own cell, stand them toward the far edge so they are
    // in front of the camera rather than around it.
    const along = z0 < 0.5 ? 0.84 : 0.55;
    const z = Math.max(z0 + (z1 - z0) * along, NEAR);
    const n = Math.min(Math.max(fig.n || 1, 1), 3);
    const small = /rat|spider|stirge|crawler|ooze|snake|centipede/.test(key);
    const large = /ogre|minotaur|golem|dragon|gorgon|manticore|griffon|chimera|tentacle|rock_man|balor/.test(key);
    const h = small ? 0.34 : large ? 0.92 : 0.70;
    const w = h * (small ? 1.05 : 0.72);
    const floor = -H;
    const mid = (x0 + x1) / 2;
    for (let i = 0; i < n; i++) {
      const cx = mid + (i - (n - 1) / 2) * (w * 0.42);
      const zi = z - i * 0.012;
      const left = proj(cx - w / 2, floor, zi);
      const right = proj(cx + w / 2, floor + h, zi);
      const x = Math.min(left[0], right[0]);
      const y = Math.min(left[1], right[1]);
      const bw = Math.abs(right[0] - left[0]);
      const bh = Math.abs(right[1] - left[1]);
      const href = fig.u || ('assets/images/battlers/' + key + '.png');
      list.push({
        z: zi - 0.006,
        svg: '<image class="fp-fig" href="' + href + '" x="' + x + '" y="' + y +
             '" width="' + bw + '" height="' + bh +
             '" preserveAspectRatio="xMidYMax meet"/>',
      });
    }
  }

  function prop(list, kind, x0, x1, z0, z1, isOpen) {
    if (!isOpen && sprite(list, kind, x0, x1, z0, z1)) return;
    const a = Math.max(z0, NEAR);
    const b = Math.max(z1, NEAR);
    if (b <= a) return;

    // Inset, so the box stands IN the cell rather than filling it wall to wall.
    const m = 0.18;
    const lx = x0 + m;
    const rx = x1 - m;
    const nz = a + (b - a) * m;
    const fz = b - (b - a) * m;
    const base = -H;
    const top = -H + (2 * H) * (PROP_H[kind] || 0.34);

    const cls = 'fp-prop is-' + kind + (isOpen ? ' is-open' : '');
    const faces = [];
    const quadOf = (pts) => '<polygon points="' + poly(pts) + '"/>';

    // The near face, the lid, and whichever side is turned toward the viewer.
    faces.push(quadOf([proj(lx, base, nz), proj(rx, base, nz),
                       proj(rx, top, nz), proj(lx, top, nz)]));
    if (isOpen) {
      // The lid stood up against the back of it, and the dark of the inside
      // where the lid used to be. Two quads rather than a hinge animation: a
      // box you can see into is the whole of what "open" has to say from six
      // feet away, and the mouth being darker than every wall face is what
      // makes it read as a hole rather than as a lighter lid.
      faces.push(quadOf([proj(lx, top, fz), proj(rx, top, fz),
                         proj(rx, top + (top - base) * 0.9, fz),
                         proj(lx, top + (top - base) * 0.9, fz)]));
      list.push({ z: a - 0.002, svg: '<g class="fp-prop-mouth">'
        + quadOf([proj(lx, top, nz), proj(rx, top, nz),
                  proj(rx, top, fz), proj(lx, top, fz)]) + '</g>' });
    } else {
      faces.push(quadOf([proj(lx, top, nz), proj(rx, top, nz),
                         proj(rx, top, fz), proj(lx, top, fz)]));
    }
    if (rx < 0) {
      faces.push(quadOf([proj(rx, base, nz), proj(rx, base, fz),
                         proj(rx, top, fz), proj(rx, top, nz)]));
    } else if (lx > 0) {
      faces.push(quadOf([proj(lx, base, nz), proj(lx, base, fz),
                         proj(lx, top, fz), proj(lx, top, nz)]));
    }

    list.push({ z: a - 0.003, svg: '<g class="' + cls + '">' + faces.join('') + '</g>' });
  }

  /** A stair, as receding treads on the floor of the cell that holds one. */
  function stair(list, x0, x1, z0, z1, down) {
    const treads = [];
    for (let i = 0; i < 4; i++) {
      const t0 = z0 + ((z1 - z0) * i) / 4;
      const t1 = z0 + ((z1 - z0) * (i + 1)) / 4;
      const a = Math.max(t0, NEAR);
      const b = Math.max(t1, NEAR);
      if (b <= a) continue;
      const rise = -H + (down ? -0.02 : 0.06 * (i + 1));
      treads.push('<polygon points="' + poly([
        proj(x0 + 0.12, rise, a), proj(x1 - 0.12, rise, a),
        proj(x1 - 0.12, rise, b), proj(x0 + 0.12, rise, b),
      ]) + '"/>');
    }
    list.push({
      z: Math.max(z0, NEAR) - 0.002,
      svg: '<g class="fp-stair is-' + (down ? 'down' : 'up') + '">' + treads.join('') + '</g>',
    });
  }

  /**
   * The view from a cursor.
   *
   * Every open cell in the frustum contributes its floor, its ceiling and
   * whatever stands on the faces around it; the quads are then painted far to
   * near, which is the whole of the hidden-surface handling. It is enough
   * because every surface here is opaque and a nearer wall's projection always
   * covers what is behind it.
   */
  function svg(tiles, cursor, opts) {
    if (!tiles || !tiles.rows || !cursor) return '';
    const o = opts || {};
    const list = [];
    const hereId = ownerAt(tiles, cursor.x, cursor.y);
    const hereFig = (tiles.figs || []).find((f) => f.l === hereId) || null;

    const fx = DX[cursor.facing];
    const fy = DY[cursor.facing];
    const rx = DX[(cursor.facing + 1) % 4];
    const ry = DY[(cursor.facing + 1) % 4];

    for (let dv = DEPTH; dv >= 0; dv--) {
      // Outside in, so a cell's own side walls land behind the ones beside it.
      const lateral = [];
      for (let du = -SPAN; du <= SPAN; du++) lateral.push(du);
      lateral.sort((a, b) => Math.abs(b) - Math.abs(a));

      for (const du of lateral) {
        const cx = cursor.x + fx * dv + rx * du;
        const cy = cursor.y + fy * dv + ry * du;
        if (!isFloor(tiles, cx, cy)) continue;

        const x0 = du - 0.5;
        const x1 = du + 0.5;
        const z0 = dv;
        const z1 = dv + 1;

        deck(list, x0, x1, z0, z1, false);
        deck(list, x0, x1, z0, z1, true);

        const st = index(tiles).stairs.get(tileOf(tiles, cx, cy));
        if (st) stair(list, x0, x1, z0, z1, st === 'down');

        // Furnishings skip the cell the party is in — a box drawn round the
        // camera is a wall across the whole picture. A waiting fight does
        // not: Gold Box put the monsters in front of you in the room you
        // just walked into, and skipping that cell hid them under your feet.
        if (du !== 0 || dv !== 0) {
          const pr = index(tiles).props.get(tileOf(tiles, cx, cy));
          if (pr) prop(list, pr.k, x0, x1, z0, z1, !!(pr.o && pr.loot));
        }
        const fig = index(tiles).figs.get(tileOf(tiles, cx, cy));
        // A fight in THIS room is always drawn on the camera cell, in front
        // of you — the party spawns on the spine, and a sprite in a far
        // corner is a picture of the wrong place. Fights down the hall stay
        // on their own tiles so you can see them through a doorway.
        if (fig && fig.l !== hereId) figure(list, fig, x0, x1, z0, z1);
        if (du === 0 && dv === 0 && hereFig) figure(list, hereFig, x0, x1, z0, z1);

        // The four faces, in the party's own frame: 0 ahead, 1 right, 2
        // behind, 3 left. The face behind is skipped for the cell you are
        // standing in — it is the wall at your back.
        for (let ld = 0; ld < 4; ld++) {
          if (ld === 2 && dv === 0 && du === 0) continue;
          const face = faceOf(tiles, cx, cy, (cursor.facing + ld) % 4);
          // Floor continuing in the same run is not a face. An OPEN DOORWAY
          // still is: skipping it made a hall into a room look like a dead
          // end, because the far wall of the room filled the corridor with
          // no jambs, or the void beyond an unvisited room sat where a
          // wall would. A hole with a frame is the sign.
          if (face.kind === 'open' && !face.door) continue;

          const shut = face.kind === 'wall';
          const gap = shut ? null : [0.32, 0.68, 0.16];

          if (ld === 0 || ld === 2) {
            const z = ld === 0 ? z1 : z0;
            const hole = gap && [x0 + gap[0], x0 + gap[1], -H + (2 * H) * 0.78];
            frontWall(list, x0, x1, z, hole);
            if (!shut && z >= NEAR) {
              leaf(list, face.kind, [
                proj(x0 + gap[0], -H, z), proj(x0 + gap[1], -H, z),
                proj(x0 + gap[1], -H + 2 * H * 0.78, z), proj(x0 + gap[0], -H + 2 * H * 0.78, z),
              ], z);
            }
          } else {
            const x = ld === 1 ? x1 : x0;
            const hole = gap && [z0 + gap[0], z0 + gap[1], -H + (2 * H) * 0.78];
            sideWall(list, x, z0, z1, hole);
            if (!shut) {
              const a = Math.max(z0 + gap[0], NEAR);
              const b = Math.max(z0 + gap[1], NEAR);
              if (b > a) {
                leaf(list, face.kind, [
                  proj(x, -H, a), proj(x, -H, b),
                  proj(x, -H + 2 * H * 0.78, b), proj(x, -H + 2 * H * 0.78, a),
                ], a);
              }
            }
          }
        }
      }
    }

    list.sort((p, q) => q.z - p.z);

    return '<svg class="fp-view" viewBox="0 0 ' + VIEW_W + ' ' + VIEW_H + '" ' +
      'preserveAspectRatio="xMidYMid meet" role="img" ' +
      'aria-label="' + (o.label || 'The way ahead') + '">' +
      defs(o.textures || 'assets/images/fp/') +
      '<rect class="fp-void" x="0" y="0" width="' + VIEW_W + '" height="' + VIEW_H + '"/>' +
      list.map((q) => q.svg).join('') +
      '</svg>';
  }

  /**
   * The corner map: where the party is on the floor they are walking.
   *
   * A WINDOW, NOT THE FLOOR. A generated level runs to 74 by 56 tiles, and all
   * of it in a panel this size is a tile a pixel across — a picture of having a
   * map rather than a map. Twenty-one by fifteen around the party is about two
   * rooms in every direction, which is the question this answers: what is
   * behind me, and did I come in that way.
   *
   * IT READS THE SAME RASTER THE VIEW DOES, which is the whole reason it can be
   * trusted. `rows` is already fogged by DelveEngine before it is shipped, so a
   * corridor the party has not walked is not in it and cannot be drawn here —
   * the mini-map inherits the fog rather than implementing a second opinion
   * about it. Same argument as the chart: the client paints what the engine
   * shipped and decides nothing.
   *
   * NORTH IS UP, and it does not rotate with the party. The chart this view
   * flips to and from is north-up, and a corner map that spun would make the
   * two disagree about which way the floor lies every time somebody turned. The
   * party's arrow carries the facing instead.
   */
  function minimap(tiles, cursor, opts) {
    if (!tiles || !cursor) return '';
    const o = opts || {};
    const halfW = o.halfW || 10;
    const halfH = o.halfH || 7;
    const cell = 4;

    const rows = tiles.rows || [];
    const W = 2 * halfW + 1;
    const H = 2 * halfH + 1;
    const x0 = cursor.x - halfW;
    const y0 = cursor.y - halfH;

    const floor = [];
    for (let j = 0; j < H; j++) {
      const ty = y0 + j;
      const row = rows[ty];
      if (typeof row !== 'string') continue;
      // Runs rather than one rect per tile: a corridor is a long line of the
      // same cell and eleven rects for it is eleven nodes the browser lays out
      // to draw one bar.
      let run = -1;
      for (let i = 0; i <= W; i++) {
        const tx = x0 + i;
        const open = i < W && tx >= 0 && tx < row.length && row[tx] !== ROCK;
        if (open && run < 0) run = i;
        if (!open && run >= 0) {
          floor.push('<rect x="' + (run * cell) + '" y="' + (j * cell) +
                     '" width="' + ((i - run) * cell) + '" height="' + cell + '"/>');
          run = -1;
        }
      }
    }

    const marks = [];
    for (const st of tiles.stairs || []) {
      const tx = st.t % tiles.w;
      const ty = Math.floor(st.t / tiles.w);
      if (tx < x0 || tx > x0 + W || ty < y0 || ty > y0 + H) continue;
      // Inset, so a stair reads as a mark ON the floor rather than as another
      // bright blob the size of the party's own arrow. The two were the same
      // gold and the same size, and the party is the one thing on this map
      // that has to be findable at a glance.
      marks.push('<rect class="mm-stair" x="' + px((tx - x0) * cell + 0.8) + '" y="' +
                 px((ty - y0) * cell + 0.8) + '" width="' + px(cell - 1.6) +
                 '" height="' + px(cell - 1.6) + '"/>');
    }

    // The party: a wedge pointing the way they are looking, drawn from the
    // centre of their own cell so it sits in the corridor rather than beside it.
    const cx = (halfW + 0.5) * cell;
    const cy = (halfH + 0.5) * cell;
    const r = cell * 1.15;
    const a = (cursor.facing * Math.PI) / 2 - Math.PI / 2;
    const pt = (ang, rad) => px(cx + Math.cos(ang) * rad) + ',' + px(cy + Math.sin(ang) * rad);
    const arrow = '<polygon class="mm-you" points="' +
      pt(a, r) + ' ' + pt(a + 2.5, r * 0.85) + ' ' + pt(a - 2.5, r * 0.85) + '"/>';

    return '<svg class="fp-mini" viewBox="0 0 ' + (W * cell) + ' ' + (H * cell) + '" ' +
      'role="img" aria-label="' + (o.label || 'Where you are on this floor') + '">' +
      '<rect class="mm-void" x="0" y="0" width="' + (W * cell) + '" height="' + (H * cell) + '"/>' +
      '<g class="mm-floor">' + floor.join('') + '</g>' +
      marks.join('') + arrow +
      '</svg>';
  }

  window.FirstPerson = {
    svg: svg,
    minimap: minimap,
    place: place,
    agrees: agrees,
    turn: turn,
    step: step,
    ownerAt: ownerAt,
    facings: ['north', 'east', 'south', 'west'],
  };
})();
