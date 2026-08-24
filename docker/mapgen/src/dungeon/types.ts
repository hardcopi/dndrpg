export type RoomShape = "rect" | "circle";

export type Dir = "n" | "s" | "e" | "w";

export type LevelId = "ground" | "below" | "above";

export type RoomRole =
  | "plaza"
  | "house"
  | "shop"
  | "temple"
  | "common"
  | "kitchen"
  | "pantry"
  | "guest"
  | "hall"
  | "stable"
  | "cellar"
  | "loft"
  | "office";

export type Room = {
  id: number;
  x: number;
  y: number;
  w: number;
  h: number;
  cx: number;
  cy: number;
  shape: RoomShape;
  role?: RoomRole;
};

export type Door = {
  x: number;
  y: number;
  dir: Dir;
  span: number;
};

export type FeatureKind =
  | "pillar"
  | "fountain"
  | "stairs-up"
  | "stairs-down"
  | "sunken"
  | "raised"
  | "entrance"
  | "rubble";

export type Feature = {
  kind: FeatureKind;
  x: number;
  y: number;
  w: number;
  h: number;
  dir?: Dir;
  to?: LevelId;
};

export type LoreNote = {
  room: number;
  text: string;
};

export type QuestStep = {
  room: number;
  text: string;
  level?: LevelId;
};

export type Quest = {
  name: string;
  hook: string;
  steps: QuestStep[];
  twist: string;
};

export type Lore = {
  title: string;
  blurb: string;
  notes: LoreNote[];
  quest: Quest;
};

export type Point = { x: number; y: number };

export type Loop = Point[];

export type MapKind = "keep" | "caves" | "wilds" | "town" | "city" | "inn";

export type Dungeon = {
  cols: number;
  rows: number;
  cell: number;
  rooms: Room[];
  doors: Door[];
  features: Feature[];
  lore: Lore;
  floor: Uint8Array;
  loops: Loop[];
  seed: number;
  kind?: MapKind;
  wall?: { x: number; y: number; w: number; h: number };
  stream?: Point[];
};

export type GenerateOptions = {
  cols: number;
  rows: number;
  cell: number;
  roomCount: number;
  corridorWidth: number;
  extraLoops: number;
  seed: number;
  kind?: MapKind;
  mode?: "ground" | "linked";
  linkKind?: "stairs-up" | "stairs-down";
  allowStairsUp?: boolean;
  allowStairsDown?: boolean;
  doors?: boolean;
  windy?: boolean;
  allCircles?: boolean;
  circleChance?: number;
  roomPad?: number;
  margin?: number;
};

export type Level = {
  id: LevelId;
  name: string;
  dungeon: Dungeon;
};

export type Keep = {
  seed: number;
  levels: Level[];
};
