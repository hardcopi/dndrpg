import { generateLore } from "./lore";
import {
  carveWindy,
  extractLoops,
  inBounds,
  idx,
  numberRoomsFromEntrance,
} from "./generate";
import { mulberry32, randInt, type Rng } from "./rng";
import type { Dungeon, Feature, GenerateOptions, Keep, Level, Room } from "./types";

function stampBlob(
  floor: Uint8Array,
  cols: number,
  rows: number,
  cx: number,
  cy: number,
  rx: number,
  ry: number,
  value: number,
  rng: Rng,
) {
  const p1 = rng() * Math.PI * 2;
  const p2 = rng() * Math.PI * 2;
  const p3 = rng() * Math.PI * 2;
  const a1 = 0.2 + rng() * 0.22;
  const a2 = 0.12 + rng() * 0.16;
  const a3 = 0.06 + rng() * 0.1;
  const pad = Math.ceil(Math.max(rx, ry) * 1.6);
  const x0 = Math.max(2, Math.floor(cx - pad));
  const y0 = Math.max(2, Math.floor(cy - pad));
  const x1 = Math.min(cols - 3, Math.ceil(cx + pad));
  const y1 = Math.min(rows - 3, Math.ceil(cy + pad));
  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const dx = (x + 0.5 - cx) / rx;
      const dy = (y + 0.5 - cy) / ry;
      const ang = Math.atan2(dy, dx);
      const r =
        1 +
        a1 * Math.sin(3 * ang + p1) +
        a2 * Math.sin(5 * ang + p2) +
        a3 * Math.sin(7 * ang + p3);
      if (dx * dx + dy * dy <= r * r) floor[idx(cols, x, y)] = value;
    }
  }
}

function smooth(floor: Uint8Array, cols: number, rows: number, times: number) {
  for (let t = 0; t < times; t++) {
    const next = new Uint8Array(floor);
    for (let y = 2; y < rows - 2; y++) {
      for (let x = 2; x < cols - 2; x++) {
        let n = 0;
        for (let oy = -1; oy <= 1; oy++) {
          for (let ox = -1; ox <= 1; ox++) {
            if (!ox && !oy) continue;
            if (floor[idx(cols, x + ox, y + oy)]) n++;
          }
        }
        const i = idx(cols, x, y);
        if (n >= 5) next[i] = 1;
        else if (n <= 2) next[i] = 0;
      }
    }
    floor.set(next);
  }
}

function largestComponent(floor: Uint8Array, cols: number, rows: number): Uint8Array {
  const seen = new Uint8Array(cols * rows);
  let best: number[] = [];
  const dirs = [
    [1, 0],
    [-1, 0],
    [0, 1],
    [0, -1],
  ];
  for (let i = 0; i < floor.length; i++) {
    if (!floor[i] || seen[i]) continue;
    const stack = [i];
    const cells: number[] = [];
    seen[i] = 1;
    while (stack.length) {
      const cur = stack.pop()!;
      cells.push(cur);
      const x = cur % cols;
      const y = (cur / cols) | 0;
      for (const [dx, dy] of dirs) {
        const nx = x + dx;
        const ny = y + dy;
        if (!inBounds(cols, rows, nx, ny)) continue;
        const ni = idx(cols, nx, ny);
        if (!floor[ni] || seen[ni]) continue;
        seen[ni] = 1;
        stack.push(ni);
      }
    }
    if (cells.length > best.length) best = cells;
  }
  const out = new Uint8Array(cols * rows);
  for (const i of best) out[i] = 1;
  return out;
}

function isConnected(floor: Uint8Array, cols: number, rows: number): boolean {
  let start = -1;
  let total = 0;
  for (let i = 0; i < floor.length; i++) {
    if (floor[i]) {
      total++;
      if (start < 0) start = i;
    }
  }
  if (start < 0) return false;
  const seen = new Uint8Array(cols * rows);
  const stack = [start];
  seen[start] = 1;
  let n = 0;
  while (stack.length) {
    const cur = stack.pop()!;
    n++;
    const x = cur % cols;
    const y = (cur / cols) | 0;
    for (const [dx, dy] of [
      [1, 0],
      [-1, 0],
      [0, 1],
      [0, -1],
    ] as const) {
      const nx = x + dx;
      const ny = y + dy;
      if (!inBounds(cols, rows, nx, ny)) continue;
      const ni = idx(cols, nx, ny);
      if (!floor[ni] || seen[ni]) continue;
      seen[ni] = 1;
      stack.push(ni);
    }
  }
  return n === total;
}

function cavernRooms(floor: Uint8Array, cols: number, rows: number): Room[] {
  const open = new Uint8Array(cols * rows);
  for (let y = 2; y < rows - 2; y++) {
    for (let x = 2; x < cols - 2; x++) {
      if (!floor[idx(cols, x, y)]) continue;
      let rock = 0;
      for (let oy = -2; oy <= 2; oy++) {
        for (let ox = -2; ox <= 2; ox++) {
          if (!floor[idx(cols, x + ox, y + oy)]) rock++;
        }
      }
      if (rock <= 6) open[idx(cols, x, y)] = 1;
    }
  }
  const seen = new Uint8Array(cols * rows);
  const rooms: Room[] = [];
  for (let y = 2; y < rows - 2; y++) {
    for (let x = 2; x < cols - 2; x++) {
      const i = idx(cols, x, y);
      if (!open[i] || seen[i]) continue;
      const stack = [i];
      seen[i] = 1;
      let minx = x,
        miny = y,
        maxx = x,
        maxy = y,
        sx = 0,
        sy = 0,
        n = 0;
      while (stack.length) {
        const cur = stack.pop()!;
        const cx = cur % cols;
        const cy = (cur / cols) | 0;
        n++;
        sx += cx;
        sy += cy;
        if (cx < minx) minx = cx;
        if (cy < miny) miny = cy;
        if (cx > maxx) maxx = cx;
        if (cy > maxy) maxy = cy;
        for (const [dx, dy] of [
          [1, 0],
          [-1, 0],
          [0, 1],
          [0, -1],
        ] as const) {
          const nx = cx + dx;
          const ny = cy + dy;
          if (!inBounds(cols, rows, nx, ny)) continue;
          const ni = idx(cols, nx, ny);
          if (!open[ni] || seen[ni]) continue;
          seen[ni] = 1;
          stack.push(ni);
        }
      }
      if (n < 18) continue;
      rooms.push({
        id: rooms.length,
        x: minx,
        y: miny,
        w: maxx - minx + 1,
        h: maxy - miny + 1,
        cx: sx / n,
        cy: sy / n,
        shape: "circle",
      });
    }
  }
  return rooms;
}

function linkRooms(rooms: Room[]): [number, number][] {
  const links: [number, number][] = [];
  if (rooms.length < 2) return links;
  const used = new Set([0]);
  while (used.size < rooms.length) {
    let best = Infinity;
    let pair: [number, number] | null = null;
    for (const i of used) {
      for (let j = 0; j < rooms.length; j++) {
        if (used.has(j)) continue;
        const dx = rooms[i]!.cx - rooms[j]!.cx;
        const dy = rooms[i]!.cy - rooms[j]!.cy;
        const d = dx * dx + dy * dy;
        if (d < best) {
          best = d;
          pair = [i, j];
        }
      }
    }
    if (!pair) break;
    links.push(pair);
    used.add(pair[1]);
  }
  return links;
}

export function generateCave(
  opts: GenerateOptions & { mouth?: "edge" | "stairs" },
): Dungeon {
  const rng = mulberry32(opts.seed);
  const { cols, rows, cell } = opts;
  const floor = new Uint8Array(cols * rows);
  const count = Math.max(5, opts.roomCount);
  const caverns: { x: number; y: number; rx: number; ry: number }[] = [];
  const margin = 8;

  for (let i = 0; i < 80 && caverns.length < count; i++) {
    const rx = randInt(rng, 4, 9);
    const ry = randInt(rng, 4, 8);
    const x = randInt(rng, margin + rx, cols - margin - rx);
    const y = randInt(rng, margin + ry, rows - margin - ry);
    const tooClose = caverns.some((c) => {
      const d = Math.hypot(c.x - x, c.y - y);
      return d < Math.max(c.rx, rx) * 0.55;
    });
    if (tooClose && rng() < 0.65) continue;
    caverns.push({ x, y, rx, ry });
    stampBlob(floor, cols, rows, x, y, rx, ry, 1, rng);
  }

  const tunnelW = Math.max(2, opts.corridorWidth);
  for (let i = 1; i < caverns.length; i++) {
    let nearest = 0;
    let best = Infinity;
    for (let j = 0; j < i; j++) {
      const d = Math.hypot(caverns[j]!.x - caverns[i]!.x, caverns[j]!.y - caverns[i]!.y);
      if (d < best) {
        best = d;
        nearest = j;
      }
    }
    const a = caverns[i]!;
    const b = caverns[nearest]!;
    const w = rng() < 0.35 ? tunnelW + 1 : rng() < 0.25 ? 2 : tunnelW;
    carveWindy(
      floor,
      cols,
      rows,
      Math.round(a.x),
      Math.round(a.y),
      Math.round(b.x),
      Math.round(b.y),
      w,
      rng,
    );
  }

  smooth(floor, cols, rows, 2);
  const kept = largestComponent(floor, cols, rows);
  floor.set(kept);

  const islands = 5 + Math.floor(count * 0.7);
  for (let i = 0; i < islands * 5 && i < 50; i++) {
    const x = randInt(rng, margin, cols - margin);
    const y = randInt(rng, margin, rows - margin);
    if (!floor[idx(cols, x, y)]) continue;
    let nearby = 0;
    for (let oy = -4; oy <= 4; oy++) {
      for (let ox = -4; ox <= 4; ox++) {
        if (!inBounds(cols, rows, x + ox, y + oy)) continue;
        if (floor[idx(cols, x + ox, y + oy)]) nearby++;
      }
    }
    if (nearby < 40) continue;
    const backup = Uint8Array.from(floor);
    const rr = randInt(rng, 2, 5);
    stampBlob(floor, cols, rows, x, y, rr, rr * (0.7 + rng() * 0.5), 0, rng);
    if (!isConnected(floor, cols, rows)) floor.set(backup);
  }

  for (let i = 0; i < 16; i++) {
    const x = randInt(rng, 5, cols - 6);
    const y = randInt(rng, 5, rows - 6);
    if (!floor[idx(cols, x, y)]) continue;
    const wall =
      !floor[idx(cols, x - 1, y)] ||
      !floor[idx(cols, x + 1, y)] ||
      !floor[idx(cols, x, y - 1)] ||
      !floor[idx(cols, x, y + 1)];
    if (!wall) continue;
    const backup = Uint8Array.from(floor);
    stampBlob(
      floor,
      cols,
      rows,
      x + randInt(rng, -1, 1),
      y + randInt(rng, -1, 1),
      randInt(rng, 2, 4),
      randInt(rng, 2, 4),
      0,
      rng,
    );
    if (!isConnected(floor, cols, rows)) floor.set(backup);
  }

  smooth(floor, cols, rows, 1);
  const again = largestComponent(floor, cols, rows);
  floor.set(again);

  let rooms = cavernRooms(floor, cols, rows);
  if (!rooms.length) {
    rooms = caverns.slice(0, 1).map((c, i) => ({
      id: i,
      x: Math.round(c.x - c.rx),
      y: Math.round(c.y - c.ry),
      w: Math.round(c.rx * 2),
      h: Math.round(c.ry * 2),
      cx: c.x,
      cy: c.y,
      shape: "circle" as const,
    }));
  }

  const mouth = opts.mouth ?? "edge";
  const features: Feature[] = [];
  if (mouth === "edge") {
    let edgeRoom = rooms[0]!;
    let edgeDir: "n" | "s" | "e" | "w" = "s";
    let bestDist = Infinity;
    for (const room of rooms) {
      const choices = [
        { dir: "n" as const, d: room.cy },
        { dir: "s" as const, d: rows - room.cy },
        { dir: "w" as const, d: room.cx },
        { dir: "e" as const, d: cols - room.cx },
      ];
      for (const c of choices) {
        if (c.d < bestDist) {
          bestDist = c.d;
          edgeRoom = room;
          edgeDir = c.dir;
        }
      }
    }
    let ex = Math.round(edgeRoom.cx);
    let ey = Math.round(edgeRoom.cy);
    if (edgeDir === "n") ey = 3;
    if (edgeDir === "s") ey = rows - 4;
    if (edgeDir === "w") ex = 3;
    if (edgeDir === "e") ex = cols - 4;
    carveWindy(
      floor,
      cols,
      rows,
      Math.round(edgeRoom.cx),
      Math.round(edgeRoom.cy),
      ex,
      ey,
      tunnelW,
      rng,
    );
    features.push({
      kind: "entrance",
      x: ex,
      y: ey,
      w: 1,
      h: 1,
      dir: edgeDir === "n" ? "s" : edgeDir === "s" ? "n" : edgeDir === "e" ? "w" : "e",
    });
  } else {
    const start = rooms[0]!;
    features.push({
      kind: "stairs-up",
      x: Math.round(start.cx) - 1,
      y: Math.round(start.cy) - 1,
      w: 2,
      h: 3,
      dir: "n",
      to: "ground",
    });
  }

  const startRoom = rooms[0]!;
  const far = rooms.reduce((a, b) => {
    const da = (a.cx - startRoom.cx) ** 2 + (a.cy - startRoom.cy) ** 2;
    const db = (b.cx - startRoom.cx) ** 2 + (b.cy - startRoom.cy) ** 2;
    return db > da ? b : a;
  }, startRoom);
  if (mouth === "edge" && rooms.length > 3 && rng() < 0.55) {
    features.push({
      kind: "stairs-down",
      x: Math.round(far.cx) - 1,
      y: Math.round(far.cy) - 1,
      w: 2,
      h: 3,
      dir: "s",
      to: "below",
    });
  }
  if (rng() < 0.4) {
    const mid = rooms[Math.floor(rooms.length / 2)]!;
    features.push({
      kind: "fountain",
      x: Math.round(mid.cx) - 1,
      y: Math.round(mid.cy) - 1,
      w: 2,
      h: 2,
    });
  }

  for (let k = 0; k < 8; k++) {
    const x = randInt(rng, 4, cols - 5);
    const y = randInt(rng, 4, rows - 5);
    if (!floor[idx(cols, x, y)]) continue;
    const wall =
      !floor[idx(cols, x - 1, y)] ||
      !floor[idx(cols, x + 1, y)] ||
      !floor[idx(cols, x, y - 1)] ||
      !floor[idx(cols, x, y + 1)];
    if (!wall && rng() > 0.2) continue;
    features.push({ kind: "rubble", x, y, w: 1, h: 1 });
  }

  const links = linkRooms(rooms);
  const numbered = numberRoomsFromEntrance(
    rooms,
    links,
    mouth === "edge"
      ? rooms.reduce((a, b) => {
          const feat = features.find((f) => f.kind === "entrance");
          if (!feat) return a;
          const da = (a.cx - feat.x) ** 2 + (a.cy - feat.y) ** 2;
          const db = (b.cx - feat.x) ** 2 + (b.cy - feat.y) ** 2;
          return db < da ? b : a;
        }, rooms[0]!).id
      : rooms[0]!.id,
  );
  const loops = extractLoops(floor, cols, rows);
  const lore = generateLore(opts.seed, numbered, features, "caves");

  return {
    cols,
    rows,
    cell,
    rooms: numbered,
    doors: [],
    features,
    lore,
    floor,
    loops,
    seed: opts.seed,
    kind: "caves",
  };
}

export function generateCaveKeep(opts: GenerateOptions): Keep {
  const ground = generateCave({ ...opts, kind: "caves" });
  const levels: Level[] = [{ id: "ground", name: "Ground", dungeon: ground }];
  if (ground.features.some((f) => f.kind === "stairs-down")) {
    const below = generateCave({
      ...opts,
      seed: (opts.seed ^ 0xb4b41) >>> 0,
      roomCount: Math.max(5, Math.round(opts.roomCount * 0.7)),
      kind: "caves",
      mouth: "stairs",
    });
    levels.push({ id: "below", name: "The Deep", dungeon: below });
  }
  return { seed: opts.seed, levels };
}
