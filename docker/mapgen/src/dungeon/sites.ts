import { generateCaveKeep } from "./caves";
import { generateWild } from "./wilds";
import { generateLore } from "./lore";
import {
  carveLine,
  carveRoom,
  connectRooms,
  extractLoops,
  findDoors,
  generateKeep,
  inBounds,
  idx,
  numberRoomsFromEntrance,
  roomsOverlap,
} from "./generate";
import { mulberry32, pick, randInt } from "./rng";
import type {
  Dir,
  Door,
  Dungeon,
  Feature,
  GenerateOptions,
  Keep,
  Level,
  MapKind,
  Room,
  RoomRole,
} from "./types";

function wrap(dungeon: Dungeon): Keep {
  return {
    seed: dungeon.seed,
    levels: [{ id: "ground", name: "Ground", dungeon }],
  };
}

function carveRing(
  floor: Uint8Array,
  cols: number,
  rows: number,
  x: number,
  y: number,
  w: number,
  h: number,
  t: number,
) {
  for (let i = 0; i < w; i++) {
    for (let k = 0; k < t; k++) {
      const top = y + k;
      const bot = y + h - 1 - k;
      if (inBounds(cols, rows, x + i, top)) floor[idx(cols, x + i, top)] = 1;
      if (inBounds(cols, rows, x + i, bot)) floor[idx(cols, x + i, bot)] = 1;
    }
  }
  for (let j = 0; j < h; j++) {
    for (let k = 0; k < t; k++) {
      const left = x + k;
      const right = x + w - 1 - k;
      if (inBounds(cols, rows, left, y + j)) floor[idx(cols, left, y + j)] = 1;
      if (inBounds(cols, rows, right, y + j)) floor[idx(cols, right, y + j)] = 1;
    }
  }
}

function generateSettlement(
  opts: GenerateOptions,
  which: "town" | "city",
): Dungeon {
  const rng = mulberry32(opts.seed);
  const cols = which === "city" ? Math.max(opts.cols, 78) : opts.cols;
  const rows = which === "city" ? Math.max(opts.rows, 58) : opts.rows;
  const count =
    which === "city"
      ? Math.max(16, Math.round(opts.roomCount * 1.7))
      : Math.max(8, opts.roomCount);
  const floor = new Uint8Array(cols * rows);
  let wall: Dungeon["wall"];
  const inner = which === "city" ? 9 : 5;
  const pw = which === "city" ? randInt(rng, 8, 12) : randInt(rng, 7, 10);
  const ph = which === "city" ? randInt(rng, 8, 11) : randInt(rng, 6, 9);
  const plaza: Room = {
    id: 0,
    x: Math.floor(cols / 2 - pw / 2),
    y: Math.floor(rows / 2 - ph / 2),
    w: pw,
    h: ph,
    cx: cols / 2,
    cy: rows / 2,
    shape: "rect",
    role: "plaza",
  };
  const rooms: Room[] = [plaza];
  const pad = which === "city" ? 2 : 3;
  const margin = inner + 1;
  for (let i = 0; i < 420 && rooms.length < count; i++) {
    const w = which === "city" ? randInt(rng, 4, 9) : randInt(rng, 5, 11);
    const h = which === "city" ? randInt(rng, 4, 8) : randInt(rng, 5, 10);
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
      shape: "rect",
    };
    if (rooms.some((r) => roomsOverlap(r, room, pad))) continue;
    rooms.push(room);
  }
  const ranked = rooms.slice(1).sort((a, b) => b.w * b.h - a.w * a.h);
  if (ranked[0]) ranked[0].role = "temple";
  for (const room of ranked.slice(1)) {
    room.role = rng() < 0.38 ? "shop" : "house";
  }
  for (const room of rooms) carveRoom(floor, cols, rows, room);
  const links = connectRooms(rng, rooms, which === "city" ? 0.28 : 0.2);
  const streetW = Math.max(2, opts.corridorWidth);
  for (const [a, b] of links) {
    const r1 = rooms[a]!;
    const r2 = rooms[b]!;
    const x1 = Math.round(r1.cx);
    const y1 = Math.round(r1.cy);
    const x2 = Math.round(r2.cx);
    const y2 = Math.round(r2.cy);
    if (rng() < 0.5) {
      carveLine(floor, cols, rows, x1, y1, x2, y1, streetW);
      carveLine(floor, cols, rows, x2, y1, x2, y2, streetW);
    } else {
      carveLine(floor, cols, rows, x1, y1, x1, y2, streetW);
      carveLine(floor, cols, rows, x1, y2, x2, y2, streetW);
    }
  }
  const roadX = Math.round(plaza.cx);
  carveLine(floor, cols, rows, roadX, Math.round(plaza.cy), roadX, rows - 3, streetW);
  carveLine(floor, cols, rows, roadX, Math.round(plaza.cy), roadX, 3, Math.max(2, streetW - 1));

  if (which === "city") {
    const minx = Math.max(2, Math.min(...rooms.map((r) => r.x)) - 4);
    const miny = Math.max(2, Math.min(...rooms.map((r) => r.y)) - 4);
    const maxx = Math.min(cols - 3, Math.max(...rooms.map((r) => r.x + r.w)) + 4);
    const maxy = Math.min(rows - 3, Math.max(...rooms.map((r) => r.y + r.h)) + 4);
    wall = { x: minx, y: miny, w: maxx - minx, h: maxy - miny };
    carveLine(floor, cols, rows, roadX, maxy, roadX, rows - 3, streetW);
    carveLine(floor, cols, rows, roadX, miny, roadX, 3, streetW);
    const midy = Math.round((miny + maxy) / 2);
    carveLine(floor, cols, rows, minx, midy, 3, midy, 2);
    carveLine(floor, cols, rows, maxx, midy, cols - 3, midy, 2);
  }

  const doors = findDoors(floor, cols, rows, rooms, streetW, rng);
  const features: Feature[] = [
    {
      kind: "fountain",
      x: Math.round(plaza.cx) - 1,
      y: Math.round(plaza.cy) - 1,
      w: 2,
      h: 2,
    },
  ];
  const temple = rooms.find((r) => r.role === "temple");
  if (temple && Math.min(temple.w, temple.h) >= 5) {
    features.push({
      kind: "pillar",
      x: Math.round(temple.cx) - 1,
      y: Math.round(temple.cy) - 1,
      w: 1,
      h: 1,
    });
    features.push({
      kind: "pillar",
      x: Math.round(temple.cx) + 1,
      y: Math.round(temple.cy) - 1,
      w: 1,
      h: 1,
    });
  }
  const loops = extractLoops(floor, cols, rows);
  const numbered = numberRoomsFromEntrance(rooms, links, 0);
  const lore = generateLore(opts.seed, numbered, features, which);
  return {
    cols,
    rows,
    cell: opts.cell,
    rooms: numbered,
    doors,
    features,
    lore,
    floor,
    loops,
    seed: opts.seed,
    kind: which,
    wall,
  };
}

function roomAt(
  id: number,
  x: number,
  y: number,
  w: number,
  h: number,
  role: RoomRole,
): Room {
  return { id, x, y, w, h, cx: x + w / 2, cy: y + h / 2, shape: "rect", role };
}

function sharedDoor(a: Room, b: Room): Door | null {
  if (a.x + a.w === b.x || b.x + b.w === a.x) {
    const left = a.x + a.w === b.x ? a : b;
    const right = left === a ? b : a;
    const y0 = Math.max(left.y, right.y) + 1;
    const y1 = Math.min(left.y + left.h, right.y + right.h) - 1;
    if (y1 - y0 < 1) return null;
    return {
      x: left.x + left.w - 1,
      y: Math.floor((y0 + y1) / 2),
      dir: "e",
      span: 1,
    };
  }
  if (a.y + a.h === b.y || b.y + b.h === a.y) {
    const top = a.y + a.h === b.y ? a : b;
    const bot = top === a ? b : a;
    const x0 = Math.max(top.x, bot.x) + 1;
    const x1 = Math.min(top.x + top.w, bot.x + bot.w) - 1;
    if (x1 - x0 < 1) return null;
    return {
      x: Math.floor((x0 + x1) / 2),
      y: top.y + top.h - 1,
      dir: "s",
      span: 1,
    };
  }
  return null;
}

function innShell(seed: number, cols: number, rows: number) {
  const rng = mulberry32(seed ^ 0x51e11);
  const bw = Math.min(cols - 16, randInt(rng, 22, 30));
  const bh = Math.min(rows - 14, randInt(rng, 16, 22));
  const bx = Math.max(4, Math.floor((cols - bw) / 2) - 4);
  const by = Math.max(4, Math.floor((rows - bh) / 2) - 3);
  return { bx, by, bw, bh };
}

function finishInn(
  opts: GenerateOptions,
  rooms: Room[],
  doors: Door[],
  features: Feature[],
  extraFloor: (floor: Uint8Array) => void,
  kindName: MapKind,
): Dungeon {
  const { cols, rows, cell, seed } = opts;
  const floor = new Uint8Array(cols * rows);
  for (const room of rooms) carveRoom(floor, cols, rows, room);
  extraFloor(floor);
  const links: [number, number][] = [];
  for (let i = 0; i < rooms.length; i++) {
    for (let j = i + 1; j < rooms.length; j++) {
      if (sharedDoor(rooms[i]!, rooms[j]!)) links.push([i, j]);
    }
  }
  const loops = extractLoops(floor, cols, rows);
  const numbered = numberRoomsFromEntrance(rooms, links, rooms[0]?.id ?? 0);
  const lore = generateLore(seed, numbered, features, kindName);
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
    seed,
    kind: "inn",
  };
}

function generateInnGround(opts: GenerateOptions): Dungeon {
  const rng = mulberry32(opts.seed);
  const { cols, rows } = opts;
  const { bx, by, bw, bh } = innShell(opts.seed, cols, rows);
  const rooms: Room[] = [];
  const commonW = Math.floor(bw * 0.62);
  const commonH = Math.floor(bh * 0.5);
  const common = roomAt(0, bx, by + bh - commonH, commonW, commonH, "common");
  rooms.push(common);
  const sideW = bw - commonW;
  const kitH = Math.max(5, Math.floor(commonH * 0.62));
  const kitchen = roomAt(1, bx + commonW, by + bh - commonH, sideW, kitH, "kitchen");
  rooms.push(kitchen);
  const pantry = roomAt(
    2,
    bx + commonW,
    by + bh - commonH + kitH,
    sideW,
    commonH - kitH,
    "pantry",
  );
  if (pantry.h >= 3) rooms.push(pantry);
  const hallW = 4;
  const northH = bh - commonH;
  const hall = roomAt(rooms.length, bx + Math.floor(commonW / 2) - 2, by, hallW, northH, "hall");
  rooms.push(hall);
  const westW = hall.x - bx;
  if (westW >= 5 && northH >= 5) {
    const split = Math.floor(northH * 0.5);
    rooms.push(roomAt(rooms.length, bx, by, westW, split, "guest"));
    rooms.push(roomAt(rooms.length, bx, by + split, westW, northH - split, "office"));
  }
  const eastX = hall.x + hall.w;
  const eastW = bx + bw - eastX;
  if (eastW >= 5 && northH >= 6) {
    const split = Math.floor(northH / 2);
    rooms.push(roomAt(rooms.length, eastX, by, eastW, split, "guest"));
    rooms.push(roomAt(rooms.length, eastX, by + split, eastW, northH - split, "guest"));
  }
  const stables = roomAt(
    rooms.length,
    bx + commonW,
    by + bh,
    Math.max(6, sideW + 2),
    6,
    "stable",
  );
  if (stables.y + stables.h < rows - 3) rooms.push(stables);

  const doors: Door[] = [];
  for (let i = 0; i < rooms.length; i++) {
    for (let j = i + 1; j < rooms.length; j++) {
      const d = sharedDoor(rooms[i]!, rooms[j]!);
      if (d) doors.push(d);
    }
  }
  doors.push({
    x: Math.round(common.cx) - 1,
    y: common.y + common.h - 1,
    dir: "s",
    span: 2,
  });

  const features: Feature[] = [
    {
      kind: "raised",
      x: common.x + 1,
      y: common.y + 1,
      w: 3,
      h: 2,
    },
  ];
  const hallRoom = rooms.find((r) => r.role === "hall");
  if (hallRoom) {
    features.push({
      kind: "stairs-up",
      x: hallRoom.x + 1,
      y: hallRoom.y + 1,
      w: 2,
      h: 3,
      dir: "n",
      to: "above",
    });
  }
  const store = rooms.find((r) => r.role === "pantry") ?? kitchen;
  features.push({
    kind: "stairs-down",
    x: store.x + 1,
    y: store.y + 1,
    w: 2,
    h: 3,
    dir: "s",
    to: "below",
  });

  return finishInn(
    opts,
    rooms,
    doors,
    features,
    (floor) => {
      const doorX = Math.round(common.cx);
      carveLine(floor, cols, rows, doorX, common.y + common.h - 1, doorX, rows - 3, 2);
    },
    "inn",
  );
}

function generateInnUpper(opts: GenerateOptions): Dungeon {
  const { cols, rows } = opts;
  const { bx, by, bw, bh } = innShell(opts.seed ^ 0x0, cols, rows);
  const rooms: Room[] = [];
  const hallW = 4;
  const hall = roomAt(0, bx + Math.floor(bw / 2) - 2, by, hallW, bh, "hall");
  rooms.push(hall);
  const westW = hall.x - bx;
  const eastX = hall.x + hall.w;
  const eastW = bx + bw - eastX;
  const slice = Math.max(5, Math.floor(bh / 3));
  let y = by;
  let id = 1;
  while (y + 4 < by + bh) {
    const h = Math.min(slice, by + bh - y);
    if (westW >= 5) rooms.push(roomAt(id++, bx, y, westW, h, "guest"));
    if (eastW >= 5) rooms.push(roomAt(id++, eastX, y, eastW, h, "guest"));
    y += h;
  }
  const doors: Door[] = [];
  for (let i = 0; i < rooms.length; i++) {
    for (let j = i + 1; j < rooms.length; j++) {
      const d = sharedDoor(rooms[i]!, rooms[j]!);
      if (d) doors.push(d);
    }
  }
  const features: Feature[] = [
    {
      kind: "stairs-down",
      x: hall.x + 1,
      y: hall.y + 1,
      w: 2,
      h: 3,
      dir: "s",
      to: "ground",
    },
  ];
  return finishInn(opts, rooms, doors, features, () => {}, "inn");
}

function generateInnCellar(opts: GenerateOptions): Dungeon {
  const { cols, rows } = opts;
  const { bx, by, bw, bh } = innShell(opts.seed ^ 0x0, cols, rows);
  const rooms: Room[] = [];
  const hall = roomAt(0, bx + Math.floor(bw / 2) - 2, by + 2, 4, bh - 4, "hall");
  rooms.push(hall);
  rooms.push(roomAt(1, bx, by + 2, hall.x - bx, Math.floor((bh - 4) / 2), "cellar"));
  rooms.push(
    roomAt(
      2,
      bx,
      by + 2 + Math.floor((bh - 4) / 2),
      hall.x - bx,
      bh - 4 - Math.floor((bh - 4) / 2),
      "cellar",
    ),
  );
  rooms.push(roomAt(3, hall.x + hall.w, by + 2, bx + bw - (hall.x + hall.w), bh - 4, "cellar"));
  const doors: Door[] = [];
  for (let i = 0; i < rooms.length; i++) {
    for (let j = i + 1; j < rooms.length; j++) {
      const d = sharedDoor(rooms[i]!, rooms[j]!);
      if (d) doors.push(d);
    }
  }
  const features: Feature[] = [
    {
      kind: "stairs-up",
      x: hall.x + 1,
      y: hall.y + 1,
      w: 2,
      h: 3,
      dir: "n",
      to: "ground",
    },
  ];
  return finishInn(opts, rooms, doors, features, () => {}, "inn");
}

function generateInn(opts: GenerateOptions): Keep {
  const ground = generateInnGround(opts);
  const levels: Level[] = [{ id: "ground", name: "Ground", dungeon: ground }];
  const upper = generateInnUpper({ ...opts, seed: opts.seed });
  for (const f of upper.features) {
    if (f.kind === "stairs-down") f.to = "ground";
  }
  levels.push({ id: "above", name: "Rooms above", dungeon: upper });
  const cellar = generateInnCellar({ ...opts, seed: opts.seed });
  levels.push({ id: "below", name: "Cellar", dungeon: cellar });
  return { seed: opts.seed, levels };
}

export function generateMap(kind: MapKind, opts: GenerateOptions): Keep {
  const base: GenerateOptions = { ...opts, kind };
  switch (kind) {
    case "caves":
      return generateCaveKeep(base);
    case "wilds":
      return wrap(generateWild(base));
    case "inn":
      return generateInn(base);
    case "town":
      return wrap(generateSettlement(base, "town"));
    case "city":
      return wrap(generateSettlement(base, "city"));
    default:
      return generateKeep(base);
  }
}
