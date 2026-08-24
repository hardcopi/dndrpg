import { generateLore } from "./lore";
import {
  carveWindy,
  extractLoops,
  idx,
  numberRoomsFromEntrance,
} from "./generate";
import { mulberry32, randInt, type Rng } from "./rng";
import type { Dungeon, Feature, GenerateOptions, Point, Room } from "./types";

function stampBlob(
  floor: Uint8Array,
  cols: number,
  rows: number,
  cx: number,
  cy: number,
  rx: number,
  ry: number,
  rng: Rng,
) {
  const p1 = rng() * Math.PI * 2;
  const p2 = rng() * Math.PI * 2;
  const a1 = 0.18 + rng() * 0.2;
  const a2 = 0.1 + rng() * 0.12;
  const pad = Math.ceil(Math.max(rx, ry) * 1.45);
  const x0 = Math.max(1, Math.floor(cx - pad));
  const y0 = Math.max(1, Math.floor(cy - pad));
  const x1 = Math.min(cols - 2, Math.ceil(cx + pad));
  const y1 = Math.min(rows - 2, Math.ceil(cy + pad));
  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const dx = (x + 0.5 - cx) / rx;
      const dy = (y + 0.5 - cy) / ry;
      const ang = Math.atan2(dy, dx);
      const r = 1 + a1 * Math.sin(3 * ang + p1) + a2 * Math.sin(5 * ang + p2);
      if (dx * dx + dy * dy <= r * r) floor[idx(cols, x, y)] = 1;
    }
  }
}

function wanderStream(
  cols: number,
  rows: number,
  rng: Rng,
): Point[] {
  const pts: Point[] = [];
  let x = 2;
  let y = randInt(rng, Math.floor(rows * 0.25), Math.floor(rows * 0.75));
  pts.push({ x, y });
  let guard = 0;
  while (x < cols - 3 && guard++ < 400) {
    const step = rng();
    if (step < 0.55) x += 1;
    else if (step < 0.75) y += randInt(rng, -1, 1) === 0 ? 1 : -1;
    else {
      x += 1;
      y += randInt(rng, -1, 1);
    }
    y = Math.max(3, Math.min(rows - 4, y));
    x = Math.max(2, Math.min(cols - 3, x));
    const last = pts[pts.length - 1]!;
    if (last.x === x && last.y === y) continue;
    pts.push({ x, y });
  }
  return pts;
}

export function generateWild(opts: GenerateOptions): Dungeon {
  const rng = mulberry32(opts.seed);
  const { cols, rows, cell } = opts;
  const floor = new Uint8Array(cols * rows);
  const count = Math.max(5, opts.roomCount);
  const clearings: { x: number; y: number; rx: number; ry: number }[] = [];
  const margin = 7;

  for (let i = 0; i < 90 && clearings.length < count; i++) {
    const rx = randInt(rng, 4, 8);
    const ry = randInt(rng, 4, 7);
    const x = randInt(rng, margin + rx, cols - margin - rx);
    const y = randInt(rng, margin + ry, rows - margin - ry);
    const close = clearings.some(
      (c) => Math.hypot(c.x - x, c.y - y) < Math.max(c.rx, rx) + 6,
    );
    if (close) continue;
    clearings.push({ x, y, rx, ry });
    stampBlob(floor, cols, rows, x, y, rx, ry, rng);
  }

  const pathW = Math.max(2, opts.corridorWidth);
  for (let i = 1; i < clearings.length; i++) {
    let nearest = 0;
    let best = Infinity;
    for (let j = 0; j < i; j++) {
      const d = Math.hypot(clearings[j]!.x - clearings[i]!.x, clearings[j]!.y - clearings[i]!.y);
      if (d < best) {
        best = d;
        nearest = j;
      }
    }
    carveWindy(
      floor,
      cols,
      rows,
      Math.round(clearings[i]!.x),
      Math.round(clearings[i]!.y),
      Math.round(clearings[nearest]!.x),
      Math.round(clearings[nearest]!.y),
      pathW,
      rng,
    );
  }

  const south = clearings.reduce((a, b) => (a.y > b.y ? a : b), clearings[0]!);
  carveWindy(
    floor,
    cols,
    rows,
    Math.round(south.x),
    Math.round(south.y),
    Math.round(south.x),
    rows - 3,
    pathW,
    rng,
  );

  const rooms: Room[] = clearings.map((c, i) => ({
    id: i,
    x: Math.round(c.x - c.rx),
    y: Math.round(c.y - c.ry),
    w: Math.round(c.rx * 2),
    h: Math.round(c.ry * 2),
    cx: c.x,
    cy: c.y,
    shape: "circle",
  }));

  const links: [number, number][] = [];
  for (let i = 1; i < rooms.length; i++) links.push([i - 1, i]);

  const features: Feature[] = [
    {
      kind: "entrance",
      x: Math.round(south.x),
      y: rows - 4,
      w: 1,
      h: 1,
      dir: "n",
    },
  ];

  const rest = rooms.filter((r) => r.cx !== south.x || r.cy !== south.y);
  const pickRoom = () => {
    if (!rest.length) return undefined;
    return rest.splice(randInt(rng, 0, rest.length - 1), 1)[0];
  };

  const pond = pickRoom();
  if (pond) {
    features.push({
      kind: "fountain",
      x: Math.round(pond.cx) - 1,
      y: Math.round(pond.cy) - 1,
      w: 2,
      h: 2,
    });
  }
  const stones = pickRoom();
  if (stones) {
    features.push({
      kind: "pillar",
      x: Math.round(stones.cx) - 1,
      y: Math.round(stones.cy) - 1,
      w: 1,
      h: 1,
    });
    features.push({
      kind: "pillar",
      x: Math.round(stones.cx) + 1,
      y: Math.round(stones.cy),
      w: 1,
      h: 1,
    });
  }
  const camp = pickRoom();
  if (camp) {
    features.push({
      kind: "raised",
      x: Math.round(camp.cx) - 1,
      y: Math.round(camp.cy) - 1,
      w: 2,
      h: 2,
    });
  }

  for (let k = 0; k < 6; k++) {
    const x = randInt(rng, 4, cols - 5);
    const y = randInt(rng, 4, rows - 5);
    if (!floor[idx(cols, x, y)]) continue;
    features.push({ kind: "rubble", x, y, w: 1, h: 1 });
  }

  const stream = wanderStream(cols, rows, rng);
  const numbered = numberRoomsFromEntrance(rooms, links, rooms.find((r) => r.cx === south.x && r.cy === south.y)?.id ?? 0);
  const lore = generateLore(opts.seed, numbered, features, "wilds");

  return {
    cols,
    rows,
    cell,
    rooms: numbered,
    doors: [],
    features,
    lore,
    floor,
    loops: extractLoops(floor, cols, rows),
    seed: opts.seed,
    kind: "wilds",
    stream,
  };
}
