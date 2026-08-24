import { mulberry32, pick, type Rng } from "./rng";
import type { Dungeon, Feature, Keep, Lore, MapKind, Quest, Room } from "./types";

const ADJ = [
  "Forgotten",
  "Lost",
  "Sunken",
  "Cursed",
  "Hidden",
  "Ancient",
  "Fallen",
  "Whispering",
  "Ashen",
  "Hollow",
  "Flooded",
  "Broken",
  "Silent",
  "Gilded",
  "Iron",
  "Bone",
  "Black",
  "Salt",
  "Verdant",
  "Bleeding",
  "Crooked",
  "Drowned",
  "Nameless",
];

const PLACE = [
  "Crypts",
  "Catacombs",
  "Temple",
  "Shrine",
  "Tomb",
  "Vault",
  "Mines",
  "Warrens",
  "Keep",
  "Halls",
  "Sanctum",
  "Laboratory",
  "Ossuary",
  "Chapel",
  "Reliquary",
  "Pits",
  "Grotto",
  "Archive",
  "Forge",
  "Cistern",
  "Ruins",
  "Fortress",
  "Priory",
  "Gaol",
];

const OF = [
  "the Last King",
  "the Serpent",
  "the Moon",
  "the Rat God",
  "the Drowned Choir",
  "the Three-Eyed Saint",
  "Vaelor",
  "Morwen",
  "Durgan",
  "Ylsa",
  "Amber",
  "the White Worm",
  "the Hollow Choir",
  "St. Brann",
  "the First Flame",
  "the Pale Court",
  "Old Hobb",
  "the Glass Saint",
];

const BUILDERS = [
  "dwarves of a vanished hold",
  "a death-cult of the old empire",
  "elves of the deep wood",
  "a wizard-tyrant and their apprentices",
  "an order of grim paladins",
  "smugglers and wreckers",
  "priests of a drowned god",
  "goblin tribes stacked generation on generation",
  "a guild of tomb-thieves",
  "engineers of a forgotten city",
  "salt-miners under a cruel charter",
  "a sisterhood that took no names",
];

const CALAMITY = [
  "a plague that left the halls unburied",
  "a cave-in that sealed the inner rooms",
  "a flood from broken cisterns",
  "a summoning that would not close",
  "civil war among its masters",
  "a curse that turned keeper against keeper",
  "fire that licked even the stone",
  "the gods going silent",
  "a siege that never lifted from within",
  "madness that spread from the well",
  "a tax that emptied it faster than war",
  "a bargain with something in the dark",
];

const OCCUPANTS = [
  "giant rats and yellow mold",
  "the restless dead",
  "goblins who have forgotten the surface",
  "a thing that sleeps lightly",
  "myconid groves in the damp",
  "bandits using the ruin as a bolt-hole",
  "slow, patient oozes",
  "cultists who have returned to finish the work",
  "stirges and pale cave-crickets",
  "the brood of something winged",
  "escaped experiments that learned the doors",
  "a hermit and the things that listen to them",
];

const RUMORS = [
  "the founder's heart still beats in a jar of brine",
  "a relic that can unmake a vow is hidden here",
  "the last thane's hoard was never carried out",
  "a prisoner of royal blood is chained below",
  "a door in the dark should not be opened",
  "maps of a greater dungeon were left in the archive",
  "drinking from the old font grants a vision, then a debt",
  "the true entrance is a stair that only appears at dusk",
  "one room is larger inside than the keep that holds it",
  "whoever lights the shrine-lamps is bound as warden",
  "the well answers questions in a voice you know",
  "a saint was buried standing up, and is not done",
];

const EMPTY = [
  "Dust and a broken stool.",
  "Empty. Scratch-marks on the door.",
  "Mildewed bedrolls, long cold.",
  "A looted weapon rack.",
  "Bones in the corner, gnawed.",
  "Soot on the ceiling. Cold hearth.",
  "An overturned table. Rats nested in it.",
  "Rusted chains on a ring in the wall.",
  "Faded mural, faces scratched out.",
  "A locked chest. It is empty.",
  "Water dripping from a hairline crack.",
  "Barrels of vinegar, gone to sludge.",
  "Graffiti in an old tongue.",
  "A poorly covered pit.",
  "Fungus on the north wall.",
  "Shrine niche, smashed.",
  "Bed of straw. Still warm.",
  "Cobwebs thick as cloth.",
  "A chalk circle, half wiped.",
  "Shelves of spoiled grain.",
  "A cage. The door hangs open.",
  "Oil stains. Someone oiled a trap.",
  "A child's toy, wooden horse.",
  "Pegs for cloaks. One remains.",
  "The floor is tiled; three tiles are false.",
  "Echoes from somewhere below.",
  "A writing desk. Ink dried to flakes.",
  "Hanging hooks. Nothing on them.",
  "A well-mouth, boarded over.",
  "Stacked coffins, all empty.",
  "Prayer-rugs eaten by moths.",
  "A mosaic of a map, incomplete.",
  "Manacles. One is freshly broken.",
  "A wine-rack. Two bottles left, both poison.",
  "Bunks for six. Occupied by none.",
  "An alchemy bench, glass fused.",
  "Dried blood in a drain.",
  "A statue's head on the floor.",
  "Lanterns, unlit, full of oil.",
  "The smell of wet dog.",
];

function take<T>(rng: Rng, items: T[]): T {
  const i = Math.floor(rng() * items.length);
  return items.splice(i, 1)[0]!;
}

function containingRoom(rooms: Room[], x: number, y: number): Room | undefined {
  return rooms.find((r) => {
    if (r.shape === "circle") {
      const dx = x + 0.5 - r.cx;
      const dy = y + 0.5 - r.cy;
      const rad = Math.min(r.w, r.h) / 2;
      return dx * dx + dy * dy <= rad * rad;
    }
    return x >= r.x && x < r.x + r.w && y >= r.y && y < r.y + r.h;
  });
}

function titleFrom(rng: Rng, place: string): string {
  const adj = pick(rng, ADJ);
  const of = pick(rng, OF);
  const roll = rng();
  if (roll < 0.22) return `The ${adj} ${place}`;
  if (roll < 0.4) return `The ${place} of ${of}`;
  if (roll < 0.55) return `The ${adj} ${place} of ${of}`;
  if (roll < 0.68) return `${place} of ${of}`;
  if (roll < 0.8) return `${of}'s ${place}`;
  if (roll < 0.9) return `Beneath ${of}`;
  return `${adj} ${place}`;
}

function featureNote(kind: Feature["kind"], rng: Rng): string | null {
  switch (kind) {
    case "fountain":
      return pick(rng, [
        "Dry fountain. Coins in the silt.",
        "Still water, black as ink.",
        "Cracked font. The basin is warm.",
        "A cistern lip. Something moved under it.",
      ]);
    case "stairs-down":
      return pick(rng, [
        "Stairs down. Cold air climbs.",
        "A descent. Fresh mud on the treads.",
        "Steps into the dark.",
        "Open stairwell. A rope is tied off.",
      ]);
    case "stairs-up":
      return pick(rng, [
        "Stairs up, choked with rubble.",
        "A stair to a collapsed storey.",
        "Steps that once met the keep above.",
      ]);
    case "entrance":
      return pick(rng, [
        "Way in. Tracks lead both ways.",
        "Threshold. The lintel is scorched.",
        "Entrance. A warning is cut in the stone.",
        "The stair from daylight. Guard-niche empty.",
      ]);
    case "sunken":
      return pick(rng, [
        "Sunken floor. Water an inch deep.",
        "The chamber has dropped.",
        "Pit-room. The edges crumble.",
      ]);
    case "raised":
      return pick(rng, [
        "Raised dais. Something sat here.",
        "A platform of older stone.",
        "Low stage. Ash in the grooves.",
      ]);
    case "pillar":
      return pick(rng, [
        "Pillars with worn faces.",
        "Columns. One is hollow.",
        "A hall of pillars. Dust in the fluting.",
      ]);
    case "rubble":
      return pick(rng, [
        "Fallen stone. Ceiling untrustworthy.",
        "Rubble slide. Bones among the blocks.",
        "Partial collapse. A gap to the next room.",
      ]);
    default:
      return null;
  }
}

function blurbFor(
  rng: Rng,
  title: string,
  place: string,
  builders: string,
  calamity: string,
  occupants: string,
  rumor: string,
): string {
  const p = place.toLowerCase();
  const occ = occupants.charAt(0).toUpperCase() + occupants.slice(1);
  const build = builders.charAt(0).toUpperCase() + builders.slice(1);
  const rumorLead = pick(rng, [
    "Locals swear",
    "A drunk in town claims",
    "The last party left a scrap:",
    "It is said",
    "Old maps mark it thus:",
    "A priest would not speak of it except to say",
  ]);
  const templates = [
    `${build} raised this ${p} before ${calamity}. ${occ} hold it now. ${rumorLead} ${rumor}.`,
    `Once a ${p}. Lost to ${calamity}. ${occ} remain. ${rumorLead} ${rumor}.`,
    `No living map names this ${p}. It fell to ${calamity}. Inside: ${occupants}. ${rumorLead} ${rumor}.`,
    `Called ${title} by those who still talk. Built by ${builders}, undone by ${calamity}. ${occ} inside.`,
    `A ${p} taken and ruined by ${calamity}. ${occ} nest in the dark. ${rumorLead} ${rumor}.`,
    `${build} walked out after ${calamity}. They left the ${p} to ${occupants}. ${rumorLead} ${rumor}.`,
    `Do not trust the doors. This ${p} knew ${calamity}, and ${occupants} learned the rest. Rumor: ${rumor}.`,
    `Under the hill, a ${p}. ${build} cut it. ${calamity.charAt(0).toUpperCase()}${calamity.slice(1)} finished it. ${occ} keep the watch.`,
  ];
  return pick(rng, templates);
}

function siteBlurb(
  rng: Rng,
  kind: Exclude<MapKind, "keep">,
  title: string,
  place: string,
  occupants: string,
  rumor: string,
): string {
  const p = place.toLowerCase();
  const occ = occupants;
  const tables: Record<Exclude<MapKind, "keep">, string[]> = {
    caves: [
      `A wet ${p}. No one cut these rooms; they grew. ${occ.charAt(0).toUpperCase()}${occ.slice(1)} use them now. It is said ${rumor}.`,
      `Locals call it ${title}. The air goes bad past the first bend. ${occ.charAt(0).toUpperCase()}${occ.slice(1)} inside.`,
      `This ${p} drinks sound. Hunters will not camp at the mouth. Rumor: ${rumor}.`,
    ],
    wilds: [
      `${title} is marked on no good map. Paths wander. ${occ.charAt(0).toUpperCase()}${occ.slice(1)} move through the clearings. Locals say ${rumor}.`,
      `A fold of ${p}. The road forgets itself here. ${occ.charAt(0).toUpperCase()}${occ.slice(1)} at night.`,
      `Woodcutters named it ${title} and then stopped going. It is said ${rumor}.`,
    ],
    town: [
      `${title} sits on a road that used to matter. Folk keep their shutters latched. ${occ.charAt(0).toUpperCase()}${occ.slice(1)} after dark. A drunk claims ${rumor}.`,
      `A small ${p}. The well is the only honest thing in it. Rumor: ${rumor}.`,
      `They still hold a market. Barely. Something is wrong with ${title}.`,
    ],
    city: [
      `${title} is a ${p} that outgrew its wall. Watchmen walk in pairs. ${occ.charAt(0).toUpperCase()}${occ.slice(1)} in the low streets. It is said ${rumor}.`,
      `Gates close at dusk. The inner streets do not. ${occ.charAt(0).toUpperCase()}${occ.slice(1)} know the posterns. Rumor: ${rumor}.`,
      `A crowded ${p}. Smoke, bells, and a secret the guilds will not print.`,
    ],
    inn: [
      `${title} takes anyone's coin. The landlord smiles too much. ${occ.charAt(0).toUpperCase()}${occ.slice(1)} in the cellar. Guests whisper ${rumor}.`,
      `A roadside ${p}. The stew is fine. The locks are not. It is said ${rumor}.`,
      `Warm fire, bad wine, worse company. Someone at ${title} is waiting for you.`,
    ],
  };
  return pick(rng, tables[kind]);
}

function furthestRoom(rooms: Room[]): Room {
  const start = rooms[0]!;
  let best = rooms[rooms.length - 1]!;
  let bestD = -1;
  for (const room of rooms) {
    const d = (room.cx - start.cx) ** 2 + (room.cy - start.cy) ** 2;
    if (d > bestD) {
      bestD = d;
      best = room;
    }
  }
  return best;
}

function roomForKind(
  rooms: Room[],
  features: Feature[],
  kind: Feature["kind"],
): number | null {
  const f = features.find((x) => x.kind === kind);
  if (!f) return null;
  const room =
    kind === "entrance"
      ? rooms[0]
      : containingRoom(rooms, f.x + f.w / 2, f.y + f.h / 2);
  return room ? room.id + 1 : null;
}

function generateQuest(
  rng: Rng,
  rooms: Room[],
  features: Feature[],
  place: string,
  occupants: string,
  rumor: string,
): Quest {
  const start = 1;
  const far = furthestRoom(rooms).id + 1;
  const fountain = roomForKind(rooms, features, "fountain");
  const down = roomForKind(rooms, features, "stairs-down");
  const up = roomForKind(rooms, features, "stairs-up");
  const dais = roomForKind(rooms, features, "raised");
  const sunken = roomForKind(rooms, features, "sunken");
  const p = place.toLowerCase();

  const mid = (avoid: number[]) => {
    const ids = rooms
      .map((r) => r.id + 1)
      .filter((n) => !avoid.includes(n));
    return ids.length ? pick(rng, ids) : Math.max(2, far);
  };

  const twists = [
    "Whoever claims the prize is bound here until another takes it.",
    "The patron already has a buyer who is not human.",
    "The occupants will not follow you into daylight. They will follow you home.",
    "The thing you came for is a lure. The true prize is the door it opens.",
    "Finishing the rite names you warden. The last warden is still here.",
    "Your employer knew the cost and did not mention it.",
  ];

  type Arch = () => Quest;
  const arches: Arch[] = [
    () => {
      const keyRoom = mid([start, far]);
      return {
        name: pick(rng, ["The Last Door", "Bring It Out", "A Light for the Dark"]),
        hook: `Someone will pay if you recover what ${occupants} are keeping in this ${p}.`,
        steps: [
          { room: start, text: "Enter without raising the whole ruin." },
          { room: keyRoom, text: "Find a token, key, or mark the inner rooms respect." },
          { room: far, text: "Take the prize. Leave by the way you came if you still can." },
        ],
        twist: pick(rng, twists),
      };
    },
  ];

  if (fountain) {
    arches.push(() => {
      const other = mid([start, fountain]);
      return {
        name: pick(rng, ["The Font's Debt", "Do Not Drink", "What the Basin Knows"]),
        hook: `The old font in this ${p} still answers if you feed it. A village wants a vision. They will not say of what.`,
        steps: [
          { room: start, text: "Bring an offering in. Wine, blood, or a name on paper." },
          { room: other, text: "Something in the rooms will try to take the offering first." },
          { room: fountain, text: "Pour it in. Ask once. Do not ask twice." },
        ],
        twist: pick(rng, [
          "The basin answers in a voice you know.",
          "Drinking the leftover water binds a debt.",
          ...twists.slice(0, 3),
        ]),
      };
    });
  }

  if (down) {
    arches.push(() => {
      const other = mid([start, down]);
      return {
        name: pick(rng, ["What Sleeps Below", "Bring Up the Prisoner", "The Cold Stair"]),
        hook: `A stair in this ${p} goes deeper than the map. A patron wants whatever is kept under it, living or not.`,
        steps: [
          { room: start, text: "Note who has been using the entrance. Fresh tracks, or none." },
          { room: other, text: "Find rope, oil, or a reason the stair is watched." },
          { room: down, text: "Descend. Bring one thing back. Close the stair if you can." },
        ],
        twist: pick(rng, [
          "The prisoner does not want to leave.",
          "The stair is a mouth. It counts what goes down.",
          ...twists.slice(2, 5),
        ]),
      };
    });
  }

  if (dais) {
    arches.push(() => {
      const other = mid([start, dais]);
      return {
        name: pick(rng, ["The Empty Seat", "The Warden's Chair", "Sit and Be Counted"]),
        hook: `The dais was a throne, an altar, or a judge's place. Sitting it is said to finish old business.`,
        steps: [
          { room: start, text: "The warning on the lintel is for this, not for thieves." },
          { room: other, text: "Recover a circlet, chain, or tablet that belongs on the dais." },
          { room: dais, text: "Set it down. Do not sit unless you mean to stay." },
        ],
        twist: pick(rng, [
          "The last warden is still in the seat, in a manner of speaking.",
          `Sitting it names you. The ${p} will not forget.`,
          ...twists.slice(0, 2),
        ]),
      };
    });
  }

  if (up) {
    arches.push(() => {
      const other = mid([start, up]);
      return {
        name: pick(rng, ["The Collapsed Storey", "Smoke Above", "A Door in the Ceiling"]),
        hook: `The ${p} once had an upper floor. A map, a body, or a bell is still up there.`,
        steps: [
          { room: start, text: "Listen. If the ruin groans, the upper floor is still shifting." },
          { room: other, text: "Find timber or a grapnel. The stair is not enough." },
          { room: up, text: "Climb. Take what you came for. The ceiling may not wait." },
        ],
        twist: pick(rng, twists),
      };
    });
  }

  if (sunken) {
    arches.push(() => {
      const other = mid([start, sunken]);
      return {
        name: pick(rng, ["An Inch of Water", "The Dropped Floor", "What the Pit Kept"]),
        hook: `A chamber in this ${p} has fallen. The village wants it searched before it falls farther.`,
        steps: [
          { room: start, text: "Keep a line back to daylight." },
          { room: other, text: "Something dry and useful was carried out of the pit recently." },
          { room: sunken, text: "Go in. The water is not only water." },
        ],
        twist: pick(rng, twists),
      };
    });
  }

  const quest = pick(rng, arches)();
  if (!quest.twist) quest.twist = `Locals already believe ${rumor}.`;
  return quest;
}

export function applyKeepQuest(keep: Keep) {
  const ground = keep.levels.find((l) => l.id === "ground")?.dungeon;
  if (!ground) return;
  const below = keep.levels.find((l) => l.id === "below")?.dungeon;
  const above = keep.levels.find((l) => l.id === "above")?.dungeon;
  if (!below && !above) return;
  const rng = mulberry32(keep.seed ^ 0x0d00d);
  const farBelow = below ? furthestRoom(below.rooms).id + 1 : 1;
  const farAbove = above ? furthestRoom(above.rooms).id + 1 : 1;
  const extra: Quest[] = [];
  if (below) {
    extra.push({
      name: pick(rng, ["What Sleeps Below", "The Undercroft", "Down the Cold Stair"]),
      hook: "The stair does not end at the landing. A patron wants whatever is kept under the keep.",
      steps: [
        { level: "ground", room: 1, text: "Find the stair down. Note who has been using it." },
        {
          level: "below",
          room: 1,
          text: "You are under the keep. The air is older here.",
        },
        {
          level: "below",
          room: farBelow,
          text: "The thing you came for is in the farthest room. Leave the way you came.",
        },
      ],
      twist: pick(rng, [
        "The stair is a mouth. It counts what goes down.",
        "The prisoner does not want to leave.",
        "Whoever claims the prize is bound here until another takes it.",
      ]),
    });
  }
  if (above) {
    extra.push({
      name: pick(rng, ["The Collapsed Storey", "Smoke Above", "A Door in the Ceiling"]),
      hook: "The keep once had an upper floor. Something is still up there.",
      steps: [
        { level: "ground", room: 1, text: "Listen. If the ruin groans, the upper floor is still shifting." },
        { level: "above", room: 1, text: "Climb. The stair opens on a smaller keep." },
        {
          level: "above",
          room: farAbove,
          text: "Take what you came for. The ceiling may not wait.",
        },
      ],
      twist: pick(rng, [
        "The upper floor is larger than the roof that covers it.",
        "Your employer knew the cost and did not mention it.",
        "The occupants will not follow you into daylight. They will follow you home.",
      ]),
    });
  }
  if (extra.length && rng() < 0.75) {
    ground.lore.quest = pick(rng, extra);
  }
}

const EMPTY_BY_KIND: Record<MapKind, string[]> = {
  keep: EMPTY,
  caves: [
    "Damp. The walls sweat.",
    "Bat guano. The ceiling rustles.",
    "A trickle of water, no source.",
    "Bones of something that was not a man.",
    "Stalagmites like teeth.",
    "A chimney of stone. Daylight, far above.",
    "Mud. Fresh prints, too many toes.",
    "A dead fire. No wood left.",
    "Crystal in the wall, cheap and pretty.",
    "The air goes bad here. Don't linger.",
    "A nest of pale crickets.",
    "Scratches counting days, then nothing.",
    "A pool you cannot see the bottom of.",
    "Collapsed. Squeeze or turn back.",
    "Echoes that are not yours.",
  ],
  wilds: [
    "A hunter's lean-to, abandoned.",
    "Standing grass. Something bedded here.",
    "A ring of mushrooms. Do not step in.",
    "Charred stumps. An old burn.",
    "A game trail crosses the clearing.",
    "Cairn of river stones.",
    "A snare, still set.",
    "Owl pellets and a snapped arrow.",
    "The trees lean in, listening.",
    "A flat stone used as a table.",
    "Horse dung, not two days old.",
    "A shrine to a roadside saint, robbed.",
    "Boggy. The ground drinks a boot.",
    "Crows. They already know.",
    "A stump with a rusted axe in it.",
  ],
  town: [
    "A cottage. The door is barred from within.",
    "Tanner's yard. The smell explains the price.",
    "A shuttered shop. Dust on the counter.",
    "Stable. One horse, lame.",
    "A widow's house. She watches from the loft.",
    "Cooper's shed. Half-made barrels.",
    "A garden gone to thistle.",
    "The miller's, quiet. No wheel turning.",
    "A tavern lean-to, locked.",
    "Smithy. The forge is cold.",
    "A house with too many cats.",
    "Grain store. Rats have voted.",
    "Chapel annex. Candles stolen.",
    "A well-house, private, and mean about it.",
    "Weaver's. Cloth on the line, still wet.",
  ],
  city: [
    "Tenement. Three families, one hearth.",
    "A counting-house. Ledgers burned.",
    "Guild warehouse. The seal is broken.",
    "Alley shrine, candle still lit.",
    "A bakehouse. The ovens are dark.",
    "Barracks of the watch. Dice on the table.",
    "A fenced garden of a minor lord.",
    "Scriptorium. Ink, no clerks.",
    "A bath-house, boarded.",
    "Money-changer's. Grate over the window.",
    "Flophouse. Straw and lice.",
    "A tower stair to nowhere.",
    "Fishmonger. The ice is gone.",
    "Advocate's rooms. Papers in drifts.",
    "A walled court. Laundry and a well.",
  ],
  inn: [
    "Guest room. The bed is made, unused.",
    "Kitchen. A pot still warm.",
    "Pantry. The good wine is hidden.",
    "A closet of brooms and a cocked crossbow.",
    "Common-room booth. Knife-marks in the wood.",
    "Landlord's office. A locked strongbox.",
    "Laundry. Blood in the wash-water.",
    "Servants' cot. A packed bag under it.",
    "Privy. Someone left in a hurry.",
    "Stable loft. A bedroll and a spyglass.",
    "Cellar stair landing. Wet footprints up.",
    "A room rented by the month. Maps on the wall.",
    "Taproom store. Empty kegs.",
    "The best room. Sheets turned down.",
    "Hearth-side nook. A half-written letter.",
  ],
};

const ROLE_NOTES: Partial<Record<NonNullable<Room["role"]>, string[]>> = {
  plaza: [
    "Market square. The well is public.",
    "The square. Notices nailed to a post.",
    "Open ground. A stall is packed down.",
  ],
  house: [
    "A cottage. Smoke from the chimney.",
    "A dwelling. The shutters are painted.",
    "Family house. A dog in the yard.",
  ],
  shop: [
    "A shop. The sign is new.",
    "Storefront. Goods in the window.",
    "A stall-house. The owner lives above.",
  ],
  temple: [
    "Chapel. The door is unlocked.",
    "A small temple. Candles in the porch.",
    "Shrine-house. Someone swept this morning.",
  ],
  common: [
    "Common room. Fire lit, benches worn.",
    "The taproom. A fiddle on the wall.",
    "Public room. The landlord watches the door.",
  ],
  kitchen: [
    "Kitchen. A pot on the hob.",
    "The cook's room. Knives in a block.",
    "Kitchen. Bread rising under a cloth.",
  ],
  pantry: [
    "Pantry. The good wine is behind the vinegar.",
    "Stores. Flour, salt, and a locked chest.",
  ],
  guest: [
    "Guest room. The bed is made.",
    "A hired room. Washbasin and a hook for a cloak.",
    "Chamber. The window looks on the yard.",
  ],
  hall: [
    "A passage. Boots dry on a peg.",
    "Hall. Stairs at the far end.",
  ],
  stable: [
    "Stables. Two stalls, one occupied.",
    "The yard stable. Tack on the wall.",
  ],
  cellar: [
    "Cellar. Casks and a cool smell.",
    "Under-croft. Root vegetables in sand.",
    "Cellar. A second door, barred.",
  ],
  loft: [
    "A room under the eaves.",
    "Loft chamber. The ceiling slants.",
  ],
  office: [
    "Landlord's office. A strongbox under the desk.",
    "Counting room. Ledgers and a bad lock.",
  ],
};

export function generateLore(
  seed: number,
  rooms: Room[],
  features: Feature[],
  kind: MapKind = "keep",
): Lore {
  const rng = mulberry32(seed ^ 0xc0ffee);
  const featKinds = new Set(features.map((f) => f.kind));

  const placeByKind: Record<MapKind, string[]> = {
    keep: PLACE,
    caves: ["Caves", "Grotto", "Caverns", "Pits", "Deeps", "Warrens"],
    wilds: ["Woods", "Wilds", "Heath", "Vale", "Moors", "Hollow"],
    town: ["Village", "Hamlet", "Town", "Crossroads", "Thorpe"],
    city: ["City", "Ward", "Borough", "Port", "Quarter"],
    inn: ["Inn", "House", "Rest", "Taproom", "Lodge"],
  };
  let place = pick(rng, placeByKind[kind]);
  if (kind === "keep") {
    if (featKinds.has("fountain")) place = pick(rng, ["Temple", "Shrine", "Cistern", "Chapel", "Sanctum", "Priory"]);
    else if (featKinds.has("sunken") || featKinds.has("stairs-down"))
      place = pick(rng, ["Crypts", "Catacombs", "Cistern", "Pits", "Grotto", "Ossuary", "Gaol"]);
    else if (featKinds.has("raised")) place = pick(rng, ["Keep", "Halls", "Sanctum", "Chapel", "Fortress", "Priory"]);
  }

  const title = titleFrom(rng, place);
  const builders = pick(rng, BUILDERS);
  const calamity = pick(rng, CALAMITY);
  const occupants = pick(rng, OCCUPANTS);
  const rumor = pick(rng, RUMORS);
  const blurb =
    kind === "keep"
      ? blurbFor(rng, title, place, builders, calamity, occupants, rumor)
      : siteBlurb(rng, kind, title, place, occupants, rumor);

  const byRoom = new Map<number, string[]>();
  const add = (roomId: number, text: string) => {
    const list = byRoom.get(roomId) ?? [];
    if (!list.includes(text)) list.push(text);
    byRoom.set(roomId, list);
  };

  for (const f of features) {
    const room =
      f.kind === "entrance"
        ? rooms[0]
        : containingRoom(rooms, f.x + f.w / 2, f.y + f.h / 2);
    if (!room) continue;
    const text = featureNote(f.kind, rng);
    if (text) add(room.id, text);
  }

  const stock = (EMPTY_BY_KIND[kind] ?? EMPTY).slice();
  const notes: Lore["notes"] = rooms.map((room) => {
    if (room.role) {
      const bag = ROLE_NOTES[room.role];
      if (bag?.length) return { room: room.id + 1, text: pick(rng, bag) };
    }
    const bits = byRoom.get(room.id);
    if (bits?.length) {
      return { room: room.id + 1, text: bits[0]! };
    }
    if (!stock.length) return { room: room.id + 1, text: "Empty." };
    return { room: room.id + 1, text: take(rng, stock) };
  });

  const quest =
    kind === "inn" || kind === "town" || kind === "city"
      ? siteQuest(rng, kind, rooms)
      : generateQuest(rng, rooms, features, place, occupants, rumor);
  return { title, blurb, notes, quest };
}

function siteQuest(rng: Rng, kind: "inn" | "town" | "city", rooms: Room[]): Quest {
  const far = furthestRoom(rooms).id + 1;
  const mid = rooms.length > 2 ? rooms[Math.floor(rooms.length / 2)]!.id + 1 : 2;
  if (kind === "inn") {
    return {
      name: pick(rng, ["The Guest Who Stayed", "A Key Behind the Bar", "After Hours"]),
      hook: "Someone paid for a room and did not sleep in it. The landlord will talk if you pay for drinks.",
      steps: [
        { room: 1, text: "Take a table. Watch who uses the inner door." },
        { room: mid, text: "The hired rooms know more than the taproom." },
        { room: far, text: "Finish it quietly. Guests are still arriving." },
      ],
      twist: pick(rng, [
        "The landlord is not the owner.",
        "The missing guest is in the cellar, and not alone.",
        "The inn keeps a room that is never let.",
      ]),
    };
  }
  if (kind === "town") {
    return {
      name: pick(rng, ["The Well", "A Closed Shop", "Bell After Dark"]),
      hook: "The village wants a quiet problem solved before the next market.",
      steps: [
        { room: 1, text: "Start at the square. Folk will lie, then point." },
        { room: mid, text: "A house that should be empty is not." },
        { room: far, text: "The last door in town. Knock or lift the latch." },
      ],
      twist: pick(rng, [
        "The well is the only witness.",
        "The reeve already sold the answer.",
        "It will follow you down the road.",
      ]),
    };
  }
  return {
    name: pick(rng, ["The Inner Gate", "A Guild Seal", "Smoke in the Ward"]),
    hook: "A patron in the city wants a thing moved, or a name taken off a list.",
    steps: [
      { room: 1, text: "Cross the square without picking a fight." },
      { room: mid, text: "A shop that fronts for someone else." },
      { room: far, text: "The last address. Do not run until you are outside the wall." },
    ],
    twist: pick(rng, [
      "The watch already has your description.",
      "The guild paid both sides.",
      "The thing you came for is a writ with your name on it.",
    ]),
  };
}

export function loreOrFallback(dungeon: Pick<Dungeon, "seed" | "rooms" | "features" | "lore">): Lore {
  if (
    dungeon.lore?.title &&
    dungeon.lore.notes?.length === dungeon.rooms.length &&
    dungeon.lore.quest?.steps?.length
  ) {
    return dungeon.lore;
  }
  return generateLore(dungeon.seed, dungeon.rooms, dungeon.features);
}
