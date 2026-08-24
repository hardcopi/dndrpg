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
    (tiles.walls || []).forEach((w) => walls.add(w.t * 4 + w.d));
    (tiles.stairs || []).forEach((s) => stairs.set(s.t, s.d));
    const fp = { doors, walls, stairs };
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
   * What one step forward means.
   *
   * Three answers and they are the whole movement model: a wall, a step inside
   * the place you are already in, or a threshold — which is a request to the
   * server and nothing this file may decide the outcome of. A locked door, a
   * trap, a wandering monster and a refusal all belong to `location/travel`,
   * exactly as they do when the chart is driving.
   */
  function step(tiles, cursor, hereId) {
    const face = faceOf(tiles, cursor.x, cursor.y, cursor.facing);
    if (face.kind === 'wall') return { blocked: true };

    const nx = cursor.x + DX[cursor.facing];
    const ny = cursor.y + DY[cursor.facing];
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
    if (kind === 'open' || kind === 'arch') return;
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
      svg: '<polygon class="' + cls + '" points="' + poly(corners) + '"/>',
    });
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

        // The four faces, in the party's own frame: 0 ahead, 1 right, 2
        // behind, 3 left. The face behind is skipped for the cell you are
        // standing in — it is the wall at your back.
        for (let ld = 0; ld < 4; ld++) {
          if (ld === 2 && dv === 0 && du === 0) continue;
          const face = faceOf(tiles, cx, cy, (cursor.facing + ld) % 4);
          if (face.kind === 'open') continue;

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

  window.FirstPerson = {
    svg: svg,
    place: place,
    agrees: agrees,
    turn: turn,
    step: step,
    ownerAt: ownerAt,
    facings: ['north', 'east', 'south', 'west'],
  };
})();
