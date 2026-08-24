import { mulberry32, pick, randInt, type Rng } from "./rng";
import { applyKeepQuest, generateLore } from "./lore";
import type {
  Dir,
  Door,
  Dungeon,
  Feature,
  GenerateOptions,
  Keep,
  Level,
  Loop,
  Point,
  Room,
} from "./types";

export function idx(cols: number, x: number, y: number) {
  return y * cols + x;
}

export function inBounds(cols: number, rows: number, x: number, y: number) {
  return x >= 0 && y >= 0 && x < cols && y < rows;
}

export function carveRoom(floor: Uint8Array, cols: number, rows: number, room: Room) {
  if (room.shape === "circle") {
    const r = Math.min(room.w, room.h) / 2;
    const cx = room.cx;
    const cy = room.cy;
    for (let y = Math.floor(room.y); y < Math.ceil(room.y + room.h); y++) {
      for (let x = Math.floor(room.x); x < Math.ceil(room.x + room.w); x++) {
        if (!inBounds(cols, rows, x, y)) continue;
        const dx = x + 0.5 - cx;
        const dy = y + 0.5 - cy;
        if (dx * dx + dy * dy <= r * r) floor[idx(cols, x, y)] = 1;
      }
    }
    return;
  }
  for (let y = room.y; y < room.y + room.h; y++) {
    for (let x = room.x; x < room.x + room.w; x++) {
      if (inBounds(cols, rows, x, y)) floor[idx(cols, x, y)] = 1;
    }
  }
}

export function carveLine(
  floor: Uint8Array,
  cols: number,
  rows: number,
  x0: number,
  y0: number,
  x1: number,
  y1: number,
  width: number,
) {
  const dx = Math.sign(x1 - x0);
  const dy = Math.sign(y1 - y0);
  const half = Math.max(0, Math.floor((width - 1) / 2));
  let x = x0;
  let y = y0;
  while (true) {
    for (let oy = -half; oy <= half; oy++) {
      for (let ox = -half; ox <= half; ox++) {
        const px = x + ox;
        const py = y + oy;
        if (inBounds(cols, rows, px, py)) floor[idx(cols, px, py)] = 1;
      }
    }
    if (x === x1 && y === y1) break;
    if (x !== x1) x += dx;
    if (y !== y1) y += dy;
  }
}

export function carveWindy(
  floor: Uint8Array,
  cols: number,
  rows: number,
  x0: number,
  y0: number,
  x1: number,
  y1: number,
  width: number,
  rng: Rng,
) {
  const half = Math.max(0, Math.floor((width - 1) / 2));
  let x = x0;
  let y = y0;
  let guard = 0;
  const stamp = (px: number, py: number) => {
    for (let oy = -half; oy <= half; oy++) {
      for (let ox = -half; ox <= half; ox++) {
        const sx = px + ox;
        const sy = py + oy;
        if (inBounds(cols, rows, sx, sy)) floor[idx(cols, sx, sy)] = 1;
      }
    }
  };
  while (guard++ < 900) {
    stamp(x, y);
    if (x === x1 && y === y1) break;
    const roll = rng();
    if (roll < 0.62) {
      if (x !== x1) x += Math.sign(x1 - x);
      else if (y !== y1) y += Math.sign(y1 - y);
    } else if (roll < 0.82) {
      if (y !== y1) y += Math.sign(y1 - y);
      else if (x !== x1) x += Math.sign(x1 - x);
    } else {
      x += randInt(rng, -1, 1);
      y += randInt(rng, -1, 1);
    }
    x = Math.max(1, Math.min(cols - 2, x));
    y = Math.max(1, Math.min(rows - 2, y));
  }
}

export function roomsOverlap(a: Room, b: Room, pad: number) {
  return (
    a.x < b.x + b.w + pad &&
    a.x + a.w + pad > b.x &&
    a.y < b.y + b.h + pad &&
    a.y + a.h + pad > b.y
  );
}

export function placeRooms(
  rng: Rng,
  cols: number,
  rows: number,
  count: number,
  style: {
    pad?: number;
    circleChance?: number;
    margin?: number;
    allCircles?: boolean;
  } = {},
): Room[] {
  const rooms: Room[] = [];
  const pad = style.pad ?? 3;
  const margin = style.margin ?? 3;
  const circleChance = style.allCircles ? 1 : (style.circleChance ?? 0.18);
  for (let i = 0; i < 280 && rooms.length < count; i++) {
    const circle = rng() < circleChance;
    const w = circle ? randInt(rng, 7, 12) : randInt(rng, 6, 14);
    const h = circle ? w : randInt(rng, 5, 11);
    if (cols - w - margin * 2 < 2 || rows - h - margin * 2 < 2) continue;
    const x = randInt(rng, margin, cols - w - margin - 1);
    const y = randInt(rng, margin, rows - h - margin - 1);
    const room: Room = {
      id: rooms.length,
      x,
      y,
      w,
      h,
      cx: x + w / 2,
      cy: y + h / 2,
      shape: circle ? "circle" : "rect",
    };
    if (rooms.some((r) => roomsOverlap(r, room, pad))) continue;
    rooms.push(room);
  }
  return rooms;
}

export function connectRooms(rng: Rng, rooms: Room[], extra: number) {
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
  const extras = Math.floor(rooms.length * extra);
  for (let i = 0; i < extras; i++) {
    const a = randInt(rng, 0, rooms.length - 1);
    let b = randInt(rng, 0, rooms.length - 1);
    if (a === b) b = (b + 1) % rooms.length;
    links.push([a, b]);
  }
  return links;
}

export function findDoors(
  floor: Uint8Array,
  cols: number,
  rows: number,
  rooms: Room[],
  corridorWidth: number,
  rng: Rng,
): Door[] {
  type Cand = { x: number; y: number; dir: Door["dir"] };
  const cands: Cand[] = [];
  const roomId = new Int16Array(cols * rows).fill(-1);
  for (const room of rooms) {
    for (let y = Math.floor(room.y); y < Math.ceil(room.y + room.h); y++) {
      for (let x = Math.floor(room.x); x < Math.ceil(room.x + room.w); x++) {
        if (!inBounds(cols, rows, x, y)) continue;
        if (floor[idx(cols, x, y)]) roomId[idx(cols, x, y)] = room.id;
      }
    }
  }
  const dirs = [
    { dx: 0, dy: -1, dir: "n" as const },
    { dx: 0, dy: 1, dir: "s" as const },
    { dx: 1, dy: 0, dir: "e" as const },
    { dx: -1, dy: 0, dir: "w" as const },
  ];
  const seen = new Set<string>();
  for (const room of rooms) {
    for (let y = Math.floor(room.y) - 1; y <= Math.ceil(room.y + room.h); y++) {
      for (let x = Math.floor(room.x) - 1; x <= Math.ceil(room.x + room.w); x++) {
        if (!inBounds(cols, rows, x, y)) continue;
        if (roomId[idx(cols, x, y)] === room.id) continue;
        if (!floor[idx(cols, x, y)]) continue;
        for (const d of dirs) {
          const nx = x + d.dx;
          const ny = y + d.dy;
          if (!inBounds(cols, rows, nx, ny)) continue;
          if (roomId[idx(cols, nx, ny)] === room.id) {
            const key = `${x},${y}`;
            if (!seen.has(key)) {
              seen.add(key);
              cands.push({ x, y, dir: d.dir });
            }
          }
        }
      }
    }
  }

  const groups: Cand[][] = [];
  const ns = new Map<string, Cand[]>();
  const ew = new Map<string, Cand[]>();
  for (const c of cands) {
    if (c.dir === "n" || c.dir === "s") {
      const k = `${c.y}:${c.dir}`;
      const list = ns.get(k);
      if (list) list.push(c);
      else ns.set(k, [c]);
    } else {
      const k = `${c.x}:${c.dir}`;
      const list = ew.get(k);
      if (list) list.push(c);
      else ew.set(k, [c]);
    }
  }
  const splitRuns = (list: Cand[], along: "x" | "y") => {
    list.sort((a, b) => a[along] - b[along]);
    let run: Cand[] = [list[0]!];
    for (let i = 1; i < list.length; i++) {
      const prev = run[run.length - 1]!;
      const cur = list[i]!;
      if (cur[along] === prev[along] + 1) run.push(cur);
      else {
        groups.push(run);
        run = [cur];
      }
    }
    groups.push(run);
  };
  for (const list of ns.values()) splitRuns(list, "x");
  for (const list of ew.values()) splitRuns(list, "y");

  const maxSpan = Math.max(1, corridorWidth);
  const isFloorCell = (x: number, y: number) =>
    inBounds(cols, rows, x, y) && floor[idx(cols, x, y)] === 1;
  const isRockCell = (x: number, y: number) => !isFloorCell(x, y);

  const isRealOpening = (g: Cand[]) => {
    if (!g.length || g.length > maxSpan) return false;
    const dir = g[0]!.dir;
    const first = g[0]!;
    const last = g[g.length - 1]!;
    if (dir === "n" || dir === "s") {
      if (!isRockCell(first.x - 1, first.y) || !isRockCell(last.x + 1, last.y)) {
        return false;
      }
      const ay = dir === "s" ? first.y - 1 : first.y + 1;
      if (!g.some((c) => isFloorCell(c.x, ay))) return false;
    } else {
      if (!isRockCell(first.x, first.y - 1) || !isRockCell(last.x, last.y + 1)) {
        return false;
      }
      const ax = dir === "e" ? first.x - 1 : first.x + 1;
      if (!g.some((c) => isFloorCell(ax, c.y))) return false;
    }
    return true;
  };

  const openings = groups.filter(isRealOpening);
  const take = (g: Cand[]): Door => {
    const span = Math.min(g.length, maxSpan);
    const start = Math.floor((g.length - span) / 2);
    const slice = g.slice(start, start + span);
    const first = slice[0]!;
    return { x: first.x, y: first.y, dir: first.dir, span };
  };

  const doors: Door[] = [];
  for (const g of openings) {
    if (rng() > 0.42) continue;
    doors.push(take(g));
  }
  if (!doors.length && openings.length) {
    doors.push(take(openings[Math.floor(rng() * openings.length)]!));
  }
  return doors;
}

function chaikin(loop: Point[], iterations: number): Point[] {
  let pts = loop;
  for (let n = 0; n < iterations; n++) {
    const next: Point[] = [];
    for (let i = 0; i < pts.length; i++) {
      const a = pts[i]!;
      const b = pts[(i + 1) % pts.length]!;
      next.push({
        x: a.x * 0.75 + b.x * 0.25,
        y: a.y * 0.75 + b.y * 0.25,
      });
      next.push({
        x: a.x * 0.25 + b.x * 0.75,
        y: a.y * 0.25 + b.y * 0.75,
      });
    }
    pts = next;
  }
  return pts;
}

export function extractLoops(floor: Uint8Array, cols: number, rows: number): Loop[] {
  const edges: Array<[string, string]> = [];
  const key = (x: number, y: number) => `${x},${y}`;
  const isFloor = (x: number, y: number) =>
    inBounds(cols, rows, x, y) && floor[idx(cols, x, y)] === 1;

  for (let y = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++) {
      if (!isFloor(x, y)) continue;
      if (!isFloor(x, y - 1)) edges.push([key(x, y), key(x + 1, y)]);
      if (!isFloor(x, y + 1)) edges.push([key(x, y + 1), key(x + 1, y + 1)]);
      if (!isFloor(x - 1, y)) edges.push([key(x, y), key(x, y + 1)]);
      if (!isFloor(x + 1, y)) edges.push([key(x + 1, y), key(x + 1, y + 1)]);
    }
  }

  const adj = new Map<string, string[]>();
  for (const [a, b] of edges) {
    if (!adj.has(a)) adj.set(a, []);
    if (!adj.has(b)) adj.set(b, []);
    adj.get(a)!.push(b);
    adj.get(b)!.push(a);
  }

  const used = new Set<string>();
  const undirected = (a: string, b: string) => (a < b ? `${a}|${b}` : `${b}|${a}`);
  const loops: Loop[] = [];

  const parse = (k: string): Point => {
    const [xs, ys] = k.split(",");
    return { x: Number(xs), y: Number(ys) };
  };

  for (const start of adj.keys()) {
    const neighbors = adj.get(start) ?? [];
    for (const n of neighbors) {
      const e0 = undirected(start, n);
      if (used.has(e0)) continue;
      const loopKeys = [start];
      let prev = start;
      let cur = n;
      used.add(e0);
      let guard = 0;
      while (cur !== start && guard++ < 20000) {
        loopKeys.push(cur);
        const nexts = (adj.get(cur) ?? []).filter((p) => {
          if (p === prev) return false;
          return !used.has(undirected(cur, p));
        });
        const nxt = nexts[0] ?? (adj.get(cur) ?? []).find((p) => p === start);
        if (!nxt) break;
        used.add(undirected(cur, nxt));
        prev = cur;
        cur = nxt;
      }
      if (loopKeys.length > 5) loops.push(chaikin(loopKeys.map(parse), 2));
    }
  }
  return loops;
}

function opposite(dir: Dir): Dir {
  if (dir === "n") return "s";
  if (dir === "s") return "n";
  if (dir === "e") return "w";
  return "e";
}

export function carveEntrance(
  floor: Uint8Array,
  cols: number,
  rows: number,
  rooms: Room[],
  width: number,
): { feature: Feature; roomId: number } | null {
  if (!rooms.length) return null;
  let best = rooms[0]!;
  let edge: Dir = "s";
  let bestDist = Infinity;
  for (const room of rooms) {
    const choices: { dir: Dir; d: number }[] = [
      { dir: "n", d: room.y },
      { dir: "s", d: rows - (room.y + room.h) },
      { dir: "w", d: room.x },
      { dir: "e", d: cols - (room.x + room.w) },
    ];
    for (const c of choices) {
      if (c.d < bestDist) {
        bestDist = c.d;
        best = room;
        edge = c.dir;
      }
    }
  }

  const cx = Math.round(best.cx);
  const cy = Math.round(best.cy);
  const half = Math.max(0, Math.floor((width - 1) / 2));
  const depth = Math.max(3, width + 1);
  const margin = 2;
  let x1 = cx;
  let y1 = cy;
  if (edge === "n") y1 = margin;
  if (edge === "s") y1 = rows - 1 - margin;
  if (edge === "w") x1 = margin;
  if (edge === "e") x1 = cols - 1 - margin;
  carveLine(floor, cols, rows, cx, cy, x1, y1, width);

  let fx = cx - half;
  let fy = cy;
  let fw = width;
  let fh = depth;
  if (edge === "n") {
    fy = y1;
    fh = depth;
    fw = width;
    fx = cx - half;
  } else if (edge === "s") {
    fy = y1 - depth + 1;
    fh = depth;
    fw = width;
    fx = cx - half;
  } else if (edge === "w") {
    fx = x1;
    fw = depth;
    fh = width;
    fy = cy - half;
  } else {
    fx = x1 - depth + 1;
    fw = depth;
    fh = width;
    fy = cy - half;
  }

  return {
    roomId: best.id,
    feature: {
      kind: "entrance",
      x: fx,
      y: fy,
      w: fw,
      h: fh,
      dir: opposite(edge),
    },
  };
}

function placeFeatures(
  rng: Rng,
  rooms: Room[],
  floor: Uint8Array,
  cols: number,
  rows: number,
  doors: Door[],
  entranceRoomId: number | null,
  corridorWidth: number,
  entranceFeature: Feature | null,
  allowUp: boolean,
  allowDown: boolean,
): Feature[] {
  const features: Feature[] = [];
  const used = new Uint8Array(cols * rows);
  const mark = (x: number, y: number, w: number, h: number) => {
    for (let yy = Math.floor(y); yy < y + h; yy++) {
      for (let xx = Math.floor(x); xx < x + w; xx++) {
        if (inBounds(cols, rows, xx, yy)) used[idx(cols, xx, yy)] = 1;
      }
    }
  };
  const free = (x: number, y: number, w: number, h: number) => {
    for (let yy = Math.floor(y); yy < y + h; yy++) {
      for (let xx = Math.floor(x); xx < x + w; xx++) {
        if (!inBounds(cols, rows, xx, yy)) return false;
        if (!floor[idx(cols, xx, yy)] || used[idx(cols, xx, yy)]) return false;
      }
    }
    return true;
  };

  for (const door of doors) {
    if (door.dir === "n" || door.dir === "s") mark(door.x, door.y, door.span, 1);
    else mark(door.x, door.y, 1, door.span);
  }
  if (entranceFeature) {
    mark(entranceFeature.x, entranceFeature.y, entranceFeature.w, entranceFeature.h);
  }

  const shuffled = rooms.slice();
  for (let i = shuffled.length - 1; i > 0; i--) {
    const j = Math.floor(rng() * (i + 1));
    const t = shuffled[i]!;
    shuffled[i] = shuffled[j]!;
    shuffled[j] = t;
  }

  let fountains = 0;
  let stairs = 0;
  let daises = 0;

  for (const room of shuffled) {
    if (room.id === entranceRoomId) continue;
    const minSide = Math.min(room.w, room.h);

    if (
      room.shape === "circle" &&
      minSide >= 7 &&
      fountains < 2 &&
      rng() < 0.55
    ) {
      const s = 2;
      const x = Math.round(room.cx - s / 2);
      const y = Math.round(room.cy - s / 2);
      if (free(x, y, s, s)) {
        features.push({ kind: "fountain", x, y, w: s, h: s });
        mark(x, y, s, s);
        fountains++;
        continue;
      }
    }

    if (
      room.shape === "circle" &&
      minSide >= 9 &&
      rng() < 0.4
    ) {
      const d = Math.max(2, Math.round(minSide * 0.22));
      const spots = [
        { x: Math.round(room.cx - d), y: Math.round(room.cy - d) },
        { x: Math.round(room.cx + d - 1), y: Math.round(room.cy - d) },
        { x: Math.round(room.cx - d), y: Math.round(room.cy + d - 1) },
        { x: Math.round(room.cx + d - 1), y: Math.round(room.cy + d - 1) },
      ];
      for (const p of spots) {
        if (free(p.x, p.y, 1, 1)) {
          features.push({ kind: "pillar", x: p.x, y: p.y, w: 1, h: 1 });
          mark(p.x, p.y, 1, 1);
        }
      }
      continue;
    }

    if (room.shape !== "circle" && room.w >= 8 && room.h >= 7 && rng() < 0.42) {
      const ix = Math.max(2, Math.floor(room.w / 3.6));
      const iy = Math.max(2, Math.floor(room.h / 3.6));
      const spots = [
        { x: room.x + ix, y: room.y + iy },
        { x: room.x + room.w - ix - 1, y: room.y + iy },
        { x: room.x + ix, y: room.y + room.h - iy - 1 },
        { x: room.x + room.w - ix - 1, y: room.y + room.h - iy - 1 },
      ];
      let placed = 0;
      for (const p of spots) {
        if (free(p.x, p.y, 1, 1)) {
          features.push({ kind: "pillar", x: p.x, y: p.y, w: 1, h: 1 });
          mark(p.x, p.y, 1, 1);
          placed++;
        }
      }
      if (placed >= 2) continue;
    }

    if (
      room.shape !== "circle" &&
      minSide >= 6 &&
      fountains < 1 &&
      rng() < 0.16
    ) {
      const s = 2;
      const x = Math.round(room.cx - s / 2);
      const y = Math.round(room.cy - s / 2);
      if (free(x, y, s, s)) {
        features.push({ kind: "fountain", x, y, w: s, h: s });
        mark(x, y, s, s);
        fountains++;
        continue;
      }
    }

    if (
      room.shape !== "circle" &&
      room.w >= 9 &&
      room.h >= 8 &&
      daises < 2 &&
      rng() < 0.24
    ) {
      const inset = 2;
      const x = room.x + inset;
      const y = room.y + inset;
      const w = room.w - inset * 2;
      const h = room.h - inset * 2;
      if (w >= 4 && h >= 3 && free(x, y, w, h)) {
        features.push({
          kind: rng() < 0.5 ? "sunken" : "raised",
          x,
          y,
          w,
          h,
        });
        mark(x + 1, y + 1, Math.max(1, w - 2), Math.max(1, h - 2));
        daises++;
      }
    }

    if (stairs >= 2) continue;
    if (minSide < 5 || rng() > 0.34) continue;
    if (!allowUp && !allowDown) continue;
    const dir = (["n", "s", "e", "w"] as const)[randInt(rng, 0, 3)]!;
    const sw = dir === "n" || dir === "s" ? Math.max(2, corridorWidth) : 3;
    const sh = dir === "n" || dir === "s" ? 3 : Math.max(2, corridorWidth);
    let sx = Math.round(room.cx - sw / 2);
    let sy = Math.round(room.cy - sh / 2);
    if (dir === "n") sy = room.y + 1;
    if (dir === "s") sy = room.y + room.h - sh - 1;
    if (dir === "w") sx = room.x + 1;
    if (dir === "e") sx = room.x + room.w - sw - 1;
    if (free(sx, sy, sw, sh)) {
      let kind: Feature["kind"] = rng() < 0.5 ? "stairs-up" : "stairs-down";
      if (kind === "stairs-up" && !allowUp) kind = "stairs-down";
      if (kind === "stairs-down" && !allowDown) kind = "stairs-up";
      features.push({
        kind,
        x: sx,
        y: sy,
        w: sw,
        h: sh,
        dir,
      });
      mark(sx, sy, sw, sh);
      stairs++;
    }
  }

  const isRock = (x: number, y: number) =>
    !inBounds(cols, rows, x, y) || floor[idx(cols, x, y)] === 0;
  const want = Math.min(9, 4 + Math.floor(rooms.length * 0.35));
  const piles: Feature[] = [];
  const spots: { x: number; y: number }[] = [];
  for (let y = 1; y < rows - 1; y++) {
    for (let x = 1; x < cols - 1; x++) {
      if (!floor[idx(cols, x, y)] || used[idx(cols, x, y)]) continue;
      const walls =
        Number(isRock(x - 1, y)) +
        Number(isRock(x + 1, y)) +
        Number(isRock(x, y - 1)) +
        Number(isRock(x, y + 1));
      if (walls === 0 && rng() > 0.06) continue;
      if (walls === 1 && rng() > 0.45) continue;
      spots.push({ x, y });
    }
  }
  for (let i = spots.length - 1; i > 0; i--) {
    const j = Math.floor(rng() * (i + 1));
    const t = spots[i]!;
    spots[i] = spots[j]!;
    spots[j] = t;
  }
  const farEnough = (x: number, y: number) =>
    piles.every((p) => Math.hypot(p.x - x, p.y - y) >= 6);

  for (const s of spots) {
    if (piles.length >= want) break;
    if (!farEnough(s.x, s.y)) continue;
    let w = rng() < 0.5 ? 2 : 1;
    let h = rng() < 0.55 ? 2 : 1;
    if (!free(s.x, s.y, w, h)) {
      w = 1;
      h = 1;
      if (!free(s.x, s.y, 1, 1)) continue;
    }
    const pile: Feature = { kind: "rubble", x: s.x, y: s.y, w, h };
    features.push(pile);
    piles.push(pile);
    mark(s.x, s.y, w, h);
  }

  return features;
}

export function numberRoomsFromEntrance(
  rooms: Room[],
  links: [number, number][],
  entranceId: number,
): Room[] {
  const byId = new Map(rooms.map((r) => [r.id, r]));
  const adj = new Map<number, number[]>();
  for (const room of rooms) adj.set(room.id, []);
  for (const [a, b] of links) {
    adj.get(a)?.push(b);
    adj.get(b)?.push(a);
  }
  for (const [id, nbs] of adj) {
    const room = byId.get(id)!;
    nbs.sort((a, b) => {
      const ra = byId.get(a)!;
      const rb = byId.get(b)!;
      const da = (ra.cx - room.cx) ** 2 + (ra.cy - room.cy) ** 2;
      const db = (rb.cx - room.cx) ** 2 + (rb.cy - room.cy) ** 2;
      return da - db;
    });
  }

  const start = byId.has(entranceId) ? entranceId : rooms[0]!.id;
  const seen = new Set<number>([start]);
  const order: number[] = [start];
  const q = [start];
  while (q.length) {
    const cur = q.shift()!;
    for (const n of adj.get(cur) ?? []) {
      if (seen.has(n)) continue;
      seen.add(n);
      order.push(n);
      q.push(n);
    }
  }
  for (const room of rooms) {
    if (!seen.has(room.id)) order.push(room.id);
  }

  const numbered = order.map((id) => byId.get(id)!);
  numbered.forEach((room, i) => {
    room.id = i;
  });
  return numbered;
}

export function generateDungeon(opts: GenerateOptions): Dungeon {
  const rng = mulberry32(opts.seed);
  const { cols, rows, cell } = opts;
  const mode = opts.mode ?? "ground";
  const allowUp = opts.allowStairsUp ?? mode === "ground";
  const allowDown = opts.allowStairsDown ?? mode === "ground";
  const floor = new Uint8Array(cols * rows);
  const rooms = placeRooms(rng, cols, rows, opts.roomCount, {
    pad: opts.roomPad,
    circleChance: opts.circleChance,
    margin: opts.margin,
    allCircles: opts.allCircles,
  });
  const links = connectRooms(rng, rooms, opts.extraLoops);
  const paint =
    opts.windy
      ? (x0: number, y0: number, x1: number, y1: number) =>
          carveWindy(floor, cols, rows, x0, y0, x1, y1, opts.corridorWidth, rng)
      : (x0: number, y0: number, x1: number, y1: number) =>
          carveLine(floor, cols, rows, x0, y0, x1, y1, opts.corridorWidth);

  for (const room of rooms) carveRoom(floor, cols, rows, room);

  for (const [a, b] of links) {
    const r1 = rooms[a]!;
    const r2 = rooms[b]!;
    const x1 = Math.round(r1.cx);
    const y1 = Math.round(r1.cy);
    const x2 = Math.round(r2.cx);
    const y2 = Math.round(r2.cy);
    if (opts.windy || rng() < 0.5) {
      paint(x1, y1, x2, y1);
      paint(x2, y1, x2, y2);
    } else {
      paint(x1, y1, x1, y2);
      paint(x1, y2, x2, y2);
    }
  }

  const entrance =
    mode === "ground"
      ? carveEntrance(floor, cols, rows, rooms, opts.corridorWidth)
      : null;

  const linkRoom = rooms[0] ?? null;
  const linkFeat =
    mode === "linked" && opts.linkKind && linkRoom
      ? forceLinkStair(linkRoom, opts.linkKind, opts.corridorWidth, rng)
      : null;

  const doors =
    opts.doors === false
      ? []
      : findDoors(floor, cols, rows, rooms, opts.corridorWidth, rng);
  const extras = placeFeatures(
    rng,
    rooms,
    floor,
    cols,
    rows,
    doors,
    entrance?.roomId ?? linkRoom?.id ?? null,
    opts.corridorWidth,
    entrance?.feature ?? linkFeat,
    allowUp,
    allowDown,
  );
  const features = [
    ...(entrance ? [entrance.feature] : []),
    ...(linkFeat ? [linkFeat] : []),
    ...extras,
  ];
  const loops = extractLoops(floor, cols, rows);
  const numbered = numberRoomsFromEntrance(
    rooms,
    links,
    entrance?.roomId ?? linkRoom?.id ?? rooms[0]?.id ?? 0,
  );
  const lore = generateLore(opts.seed, numbered, features, opts.kind ?? "keep");

  return {
    cols,
    rows,
    cell,
    rooms: numbered,
    doors,
    features,
    lore,
    floor,
    loops,
    seed: opts.seed,
    kind: opts.kind ?? "keep",
  };
}

function forceLinkStair(
  room: Room,
  kind: "stairs-up" | "stairs-down",
  corridorWidth: number,
  rng: Rng,
): Feature {
  const dir = (["n", "s", "e", "w"] as const)[randInt(rng, 0, 3)]!;
  const sw = dir === "n" || dir === "s" ? Math.max(2, corridorWidth) : 3;
  const sh = dir === "n" || dir === "s" ? 3 : Math.max(2, corridorWidth);
  let sx = Math.round(room.cx - sw / 2);
  let sy = Math.round(room.cy - sh / 2);
  if (dir === "n") sy = room.y + 1;
  if (dir === "s") sy = Math.max(room.y, room.y + room.h - sh - 1);
  if (dir === "w") sx = room.x + 1;
  if (dir === "e") sx = Math.max(room.x, room.x + room.w - sw - 1);
  sx = Math.max(room.x, Math.min(sx, room.x + room.w - sw));
  sy = Math.max(room.y, Math.min(sy, room.y + room.h - sh));
  return { kind, x: sx, y: sy, w: sw, h: sh, dir };
}

const BELOW_NAMES = ["Undercroft", "Crypts", "Cellars", "The Deep", "Ossuary"];
const ABOVE_NAMES = ["Upper Halls", "The Loft", "Collapsed Storey", "The Ruin Above"];

export function generateKeep(opts: GenerateOptions): Keep {
  const rng = mulberry32(opts.seed ^ 0x51e1);
  const ground = generateDungeon({
    ...opts,
    mode: "ground",
    allowStairsUp: true,
    allowStairsDown: true,
  });
  const levels: Level[] = [{ id: "ground", name: "Ground", dungeon: ground }];

  const hasDown = ground.features.some((f) => f.kind === "stairs-down");
  const hasUp = ground.features.some((f) => f.kind === "stairs-up");

  if (hasDown) {
    const below = generateDungeon({
      ...opts,
      cols: Math.max(52, opts.cols - 12),
      rows: Math.max(40, opts.rows - 10),
      roomCount: Math.max(5, Math.round(opts.roomCount * 0.55)),
      seed: (opts.seed ^ 0xb4b41) >>> 0,
      mode: "linked",
      linkKind: "stairs-up",
      allowStairsUp: false,
      allowStairsDown: false,
    });
    for (const f of ground.features) {
      if (f.kind === "stairs-down") f.to = "below";
    }
    for (const f of below.features) {
      if (f.kind === "stairs-up") f.to = "ground";
    }
    levels.push({ id: "below", name: pick(rng, BELOW_NAMES), dungeon: below });
  }

  if (hasUp) {
    const above = generateDungeon({
      ...opts,
      cols: Math.max(48, opts.cols - 16),
      rows: Math.max(36, opts.rows - 14),
      roomCount: Math.max(4, Math.round(opts.roomCount * 0.45)),
      seed: (opts.seed ^ 0xa11e) >>> 0,
      mode: "linked",
      linkKind: "stairs-down",
      allowStairsUp: false,
      allowStairsDown: false,
    });
    for (const f of ground.features) {
      if (f.kind === "stairs-up") f.to = "above";
    }
    for (const f of above.features) {
      if (f.kind === "stairs-down") f.to = "ground";
    }
    levels.push({ id: "above", name: pick(rng, ABOVE_NAMES), dungeon: above });
  }

  const keep = { seed: opts.seed, levels };
  applyKeepQuest(keep);
  return keep;
}
