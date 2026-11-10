/* Scene records use scene-box percentages; drop an image and add an entry to add a scene.
 * Act I path: 51 painted scenes across six regions; Act II adds Brackwater (7) (see images/scenes/README.md). */
window.RIVERMARK_SCENES = {
  // ── Rivermark ──
  market_square: {
    image: 'assets/images/scenes/market_square.jpg',
    subtitle: 'the heart of Rivermark',
    exits: {
      tobins_goods: { x: 10.593, y: 22.976, icon: '⌂' },
      high_street: { x: 36.547, y: 46.328, icon: '↓' },
      counting_house: { x: 74.153, y: 28.249, icon: '↑' },
      the_anvil: { x: 92.373, y: 54.237, icon: '⚒' }
    },
    npcs: {
      captain_elowen: { x: 57.8, y: 60.6 },
      sera: { x: 11.9, y: 53.9 },
      market_farmer: { x: 21, y: 63 },
      market_baker: { x: 55, y: 42 }
    },
    objects: [
      { label: 'the well', x: 58, y: 70, act: 'search' },
      { label: "Tobin's notice", x: 12, y: 30, act: 'search' }
    ],
    fx: [
      { type: 'smoke', x: 85.7, y: -8, w: 13, h: 17.5, intensity: 1, wind: 0.6 },
      { type: 'smoke', x: 47.5, y: 1, w: 7, h: 12.5, intensity: 0.35, wind: 0.5 },
      { type: 'fire', x: 90.5, y: 50, w: 7.5, h: 15, intensity: 1 },
      { type: 'birds', x: 22, y: 2, w: 66, h: 18, intensity: 0.8 },
      { type: 'water', x: 70, y: 49.5, w: 15, h: 9, intensity: 1, count: 34, clip: [[70,49.3],[85,49.3],[85,51.5],[82,53],[80,58],[77.5,58.3],[76.5,54],[70,53.6]] },
      { type: 'dust', x: 22, y: 30, w: 46, h: 50, intensity: 0.45, count: 30 },
      { type: 'sway', x: 80.3, y: 37.2, w: 6.5, h: 12, amp: 1.8, period: 3.2 },
      { type: 'sway', x: 3.4, y: 19.6, w: 9.4, h: 18, amp: 0.9, period: 4.4 }
    ]
  },
  counting_house: {
    image: 'assets/images/scenes/counting_house.jpg',
    exits: {
      market_square: {x: 6, y: 50, icon: "←"},
      granary_row: {x: 46, y: 42, icon: "↑"},
      river_stair: {x: 76, y: 80, icon: "↓"}
    },
    npcs: {
      tibald_ashe: {x: 34, y: 38}
    },
    objects: [
      {label: "Open ledgers", x: 35, y: 70, act: "search"},
      {label: "Strongbox", x: 60, y: 46, act: "search"},
      {label: "Coin scales", x: 65, y: 50, act: "search"},
      {label: "River window", x: 93, y: 40, act: "search"}
    ],
    fx: [
      {type: "candle", x: 17.5, y: 43, w: 4, h: 9, intensity: 0.9},
      {type: "candle", x: 38.5, y: 41, w: 3.5, h: 8, intensity: 0.8},
      {type: "candle", x: 51.5, y: 41, w: 3.5, h: 8, intensity: 0.8},
      {type: "candle", x: 63.5, y: 39, w: 4, h: 9, intensity: 0.8},
      {type: "water", x: 90, y: 40, w: 8, h: 14, intensity: 0.4, count: 8, clip: [[90, 41], [98, 41], [98, 54], [90, 54]]},
      {type: "dust", x: 55, y: 10, w: 40, h: 60, intensity: 0.5, count: 22, shaft: true, angle: -25}
    ]
  },
  river_stair: {
    image: 'assets/images/scenes/river_stair.jpg',
    exits: {
      counting_house: {x: 10, y: 28, icon: "↑"},
      granary_row: {x: 85, y: 38, icon: "→"},
      towpath_end: {x: 92, y: 62, icon: "↓"}
    },
    npcs: {
      mott_wenn: {x: 36, y: 56},
      sib_marrell: {x: 18, y: 74}
    },
    objects: [
      {label: "Flat boat", x: 58, y: 68, act: "search"},
      {label: "Gangplank", x: 46, y: 82, act: "search"},
      {label: "Iron rings", x: 16, y: 82, act: "search"},
      {label: "Tide-scale", x: 14, y: 48, act: "search"}
    ],
    fx: [
      {type: "water", x: 24, y: 40, w: 68, h: 46, intensity: 1, count: 60, clip: [[24, 42], [60, 40], [92, 47], [84, 63], [78, 78], [56, 88], [45, 82], [44, 64], [24, 62]]},
      {type: "birds", x: 15, y: 2, w: 65, h: 22, intensity: 0.9},
      {type: "smoke", x: 72, y: -4, w: 6, h: 12, intensity: 0.3, wind: 0.4}
    ]
  },
  tobins_goods: {
    image: 'assets/images/scenes/tobins_goods.jpg',
    exits: {
      market_square: {x: 9, y: 52, icon: "←"}
    },
    npcs: {
      tobin: {x: 62, y: 42}
    },
    objects: [
      {label: "Brass scales", x: 42, y: 48, act: "search"},
      {label: "Price slate", x: 27, y: 25, act: "search"},
      {label: "Rope and salt", x: 88, y: 45, act: "search"},
      {label: "Behind the counter", x: 75, y: 25, act: "search"}
    ],
    fx: [
      {type: "dust", x: 18, y: 8, w: 34, h: 62, intensity: 0.6, count: 26, shaft: true, angle: -30},
      {type: "candle", x: 40, y: 12, w: 4, h: 9, intensity: 0.8},
      {type: "sway", x: 39.5, y: 0, w: 5, h: 24, amp: 1.2, period: 3.8, pivot: [50, 0]}
    ]
  },
  the_anvil: {
    image: 'assets/images/scenes/the_anvil.jpg',
    exits: {
      market_square: {x: 19, y: 42, icon: "←"}
    },
    npcs: {
      brenna_smith: {x: 37, y: 50}
    },
    objects: [
      {label: "The anvil", x: 47, y: 72, act: "search"},
      {label: "Forge", x: 85, y: 55, act: "search"},
      {label: "Quench trough", x: 68, y: 80, act: "search"},
      {label: "Blades and tools", x: 58, y: 25, act: "search"},
      {label: "Counter", x: 9, y: 76, act: "search"}
    ],
    fx: [
      {type: "fire", x: 76, y: 35, w: 16, h: 27, intensity: 1.1},
      {type: "fire", x: 75, y: 68, w: 10, h: 12, intensity: 0.5},
      {type: "smoke", x: 61, y: 56, w: 9, h: 16, intensity: 0.5, wind: 0.1, color: "#e8e4dc"},
      {type: "sway", x: 93, y: 26, w: 7, h: 32, amp: 1, period: 3.4, pivot: [50, 0]},
      {type: "dust", x: 30, y: 20, w: 50, h: 50, intensity: 0.35, count: 16}
    ]
  },
  high_street: {
    image: 'assets/images/scenes/high_street.jpg',
    exits: {
      flagon_common_room: {x: 8, y: 64, icon: "⌂"},
      root_ash: {x: 92, y: 58, icon: "⌂"},
      east_gate: {x: 61, y: 50, icon: "↑"},
      granary_row: {x: 44, y: 60, icon: "↓"},
      market_square: {x: 50, y: 95, icon: "↑"},
      west_gate: {x: 97, y: 90, icon: "→"}
    },
    npcs: {
      street_minstrel: {x: 51, y: 76},
      street_drover: {x: 63, y: 72},
      street_seer: {x: 78, y: 80},
      street_scholar: {x: 39, y: 69},
      watch_street: {x: 68, y: 66},
      street_almoner: {x: 24, y: 84}
    },
    objects: [],
    fx: [
      {type: "smoke", x: 67, y: -4, w: 7, h: 12, intensity: 0.5, wind: 0.4},
      {type: "smoke", x: 74, y: -6, w: 7, h: 12, intensity: 0.5, wind: 0.4},
      {type: "smoke", x: 40, y: 8, w: 5, h: 10, intensity: 0.25, wind: 0.4},
      {type: "sway", x: 3, y: 15, w: 9, h: 25, amp: 1, period: 4.2, pivot: [30, 0]},
      {type: "sway", x: 75, y: 29, w: 8, h: 16, amp: 1, period: 3.7, pivot: [60, 0]},
      {type: "birds", x: 30, y: 2, w: 50, h: 18, intensity: 0.7},
      {type: "dust", x: 25, y: 40, w: 50, h: 45, intensity: 0.35, count: 20},
      {type: "candle", x: 18, y: 48, w: 5, h: 10, intensity: 0.6}
    ]
  },
  west_gate: {
    image: 'assets/images/scenes/west_gate.jpg',
    exits: {
      ford_lane: {x: 34, y: 52, icon: "↑"},
      high_street: {x: 8, y: 90, icon: "↑"}
    },
    npcs: {
      serjeant_ballow: {x: 36, y: 22}
    },
    objects: [
      {label: "Broken gate leaf", x: 26, y: 62, act: "search"},
      {label: "Chalk tally", x: 45, y: 55, act: "search"},
      {label: "Empty cart", x: 66, y: 74, act: "search"},
      {label: "Burnt mill", x: 80, y: 36, act: "search"}
    ],
    fx: [
      {type: "fog", x: 0, y: 34, w: 100, h: 22, intensity: 0.6, wind: 0.3},
      {type: "water", x: 78, y: 58, w: 22, h: 40, intensity: 0.6, count: 18, clip: [[84, 58], [100, 60], [100, 75], [90, 72], [78, 63]]},
      {type: "birds", x: 45, y: 5, w: 50, h: 20, intensity: 0.6}
    ]
  },
  root_ash: {
    image: 'assets/images/scenes/root_ash.jpg',
    exits: {
      high_street: {x: 8, y: 55, icon: "←"}
    },
    npcs: {
      osric_apothecary: {x: 61, y: 48}
    },
    objects: [
      {label: "Mortar and pestle", x: 56, y: 62, act: "search"},
      {label: "Ledger", x: 67, y: 69, act: "search"},
      {label: "Balance", x: 47, y: 60, act: "search"},
      {label: "Drying herbs", x: 62, y: 14, act: "search"},
      {label: "Stoppered bottles", x: 35, y: 40, act: "search"}
    ],
    fx: [
      {type: "sway", x: 41, y: 0, w: 5, h: 27, amp: 1.1, period: 4.8},
      {type: "sway", x: 55.5, y: 0, w: 5, h: 25, amp: 0.9, period: 5.3},
      {type: "sway", x: 68, y: 0, w: 6, h: 24, amp: 1, period: 4.4},
      {type: "candle", x: 93.5, y: 24, w: 5, h: 10, intensity: 0.8},
      {type: "dust", x: 72, y: 20, w: 22, h: 55, intensity: 0.5, count: 16, shaft: true, angle: -20}
    ]
  },
  granary_row: {
    image: 'assets/images/scenes/granary_row.jpg',
    exits: {
      counting_house: {x: 13, y: 33, icon: "←"},
      river_stair: {x: 5, y: 70, icon: "←"},
      grain_stair: {x: 34, y: 88, icon: "↓"},
      high_street: {x: 97, y: 80, icon: "→"}
    },
    npcs: {
      pol_reddick: {x: 88, y: 74},
      hollis_marrow: {x: 58, y: 82},
      alder_pyke: {x: 22, y: 76},
      hesper_amsel: {x: 47, y: 62}
    },
    objects: [
      {label: "Beam scale", x: 75, y: 55, act: "search"},
      {label: "Loft door, ajar", x: 56, y: 14, act: "search"},
      {label: "Grain cart", x: 50, y: 64, act: "search"}
    ],
    fx: [
      {type: "water", x: 0, y: 58, w: 11, h: 22, intensity: 0.8, count: 14, clip: [[0, 60], [8, 60], [10, 70], [5, 78], [0, 78]]},
      {type: "birds", x: 0, y: 0, w: 45, h: 22, intensity: 0.6},
      {type: "dust", x: 28, y: 35, w: 55, h: 40, intensity: 0.45, count: 24},
      {type: "sway", x: 73, y: 11, w: 6, h: 36, amp: 0.8, period: 4.4, pivot: [50, 0]},
      {type: "smoke", x: 30, y: 0, w: 6, h: 10, intensity: 0.3, wind: 0.5}
    ]
  },
  flagon_common_room: {
    image: 'assets/images/scenes/flagon_common_room.jpg',
    exits: {
      high_street: {x: 5, y: 46, icon: "←"},
      flagon_cellar: {x: 23, y: 86, icon: "↓"}
    },
    npcs: {
      mara_hearthstone: {x: 35, y: 40},
      kessa: {x: 47, y: 60},
      garrow_finch: {x: 64, y: 62},
      inn_sleeper: {x: 80, y: 70},
      aldric: {x: 91, y: 84}
    },
    objects: [
      {label: "Job board", x: 17, y: 33, act: "board"},
      {label: "Hearth", x: 90, y: 58, act: "search"},
      {label: "The bar", x: 35, y: 62, act: "search"},
      {label: "Stairs to rooms", x: 50, y: 40, act: "search"}
    ],
    fx: [
      {type: "hearth", x: 83, y: 40, w: 14, h: 28, intensity: 1},
      {type: "candle", x: 78, y: 58, w: 3.5, h: 8, intensity: 0.8},
      {type: "candle", x: 60.5, y: 20, w: 5, h: 10, intensity: 0.6},
      {type: "smoke", x: 70, y: 26, w: 6, h: 16, intensity: 0.2, wind: 0.2},
      {type: "dust", x: 25, y: 15, w: 60, h: 50, intensity: 0.3, count: 14}
    ]
  },
  flagon_cellar: {
    image: 'assets/images/scenes/flagon_cellar.jpg',
    exits: {
      flagon_common_room: {x: 15, y: 42, icon: "↑"},
      sump_drain: {x: 70, y: 34, icon: "↑"}
    },
    npcs: {},
    objects: [
      {label: "Gnawed sacks", x: 35, y: 68, act: "search"},
      {label: "Kegs on chocks", x: 48, y: 62, act: "search"},
      {label: "The sump", x: 78, y: 82, act: "search"},
      {label: "Spilled grain", x: 10, y: 86, act: "search"}
    ],
    fx: [
      {type: "candle", x: 37.5, y: 26, w: 5, h: 12, intensity: 1},
      {type: "drip", x: 62, y: 18, w: 20, h: 62, intensity: 0.8, count: 3},
      {type: "water", x: 70, y: 75, w: 16, h: 18, intensity: 0.5, count: 10, clip: [[70, 78], [84, 76], [86, 90], [72, 92]]},
      {type: "dust", x: 20, y: 25, w: 40, h: 45, intensity: 0.25, count: 10}
    ]
  },
  east_gate: {
    image: 'assets/images/scenes/east_gate.jpg',
    exits: {
      quarry_road: {x: 64, y: 52, icon: "↑"},
      high_street: {x: 50, y: 95, icon: "↑"}
    },
    npcs: {
      watch_gate: {x: 53, y: 70},
      street_cutpurse: {x: 15, y: 76}
    },
    objects: [
      {label: "Tarped cart", x: 42, y: 74, act: "search"},
      {label: "Portcullis", x: 64, y: 40, act: "search"}
    ],
    fx: [
      {type: "torch", x: 46.5, y: 51, w: 4, h: 9, intensity: 1},
      {type: "torch", x: 72.5, y: 53, w: 4, h: 9, intensity: 1},
      {type: "birds", x: 40, y: 0, w: 55, h: 18, intensity: 0.7},
      {type: "dust", x: 55, y: 50, w: 25, h: 30, intensity: 0.35, count: 12},
      {type: "smoke", x: 96, y: -4, w: 5, h: 12, intensity: 0.3, wind: 0.3}
    ]
  },
  // ── Greyhythe ──
  towpath_end: {
    image: 'assets/images/scenes/towpath_end.jpg',
    exits: {
      river_stair: {x: 11, y: 44, icon: "↑"},
      the_hythe: {x: 78, y: 62, icon: "⌂"}
    },
    npcs: {},
    objects: [
      {label: "Rope-worn bollard", x: 57, y: 76, act: "search"},
      {label: "Tow horse", x: 33, y: 60, act: "search"},
      {label: "Boat shed", x: 60, y: 50, act: "search"},
      {label: "New cobbles", x: 82, y: 85, act: "search"}
    ],
    fx: [
      {type: "water", x: 0, y: 42, w: 45, h: 33, intensity: 1, count: 40, clip: [[0, 52], [25, 45], [42, 43], [44, 50], [30, 62], [18, 68], [0, 74]]},
      {type: "birds", x: 15, y: 2, w: 55, h: 20, intensity: 0.9},
      {type: "smoke", x: 81, y: -6, w: 7, h: 14, intensity: 0.5, wind: 0.4},
      {type: "dust", x: 50, y: 40, w: 40, h: 40, intensity: 0.3, count: 12}
    ]
  },
  the_hythe: {
    image: 'assets/images/scenes/the_hythe.jpg',
    exits: {
      towpath_end: {x: 3, y: 82, icon: "←"},
      salt_house: {x: 19, y: 44, icon: "↑"},
      corse_landing: {x: 88, y: 42, icon: "↓"},
      packet_stair: {x: 66, y: 57, icon: "↑"}
    },
    npcs: {},
    objects: [
      {label: "Net menders", x: 62, y: 78, act: "search"},
      {label: "Empty rings", x: 40, y: 64, act: "search"},
      {label: "Rowboat", x: 37, y: 74, act: "search"}
    ],
    fx: [
      {type: "water", x: 60, y: 43, w: 40, h: 30, intensity: 1, count: 36, clip: [[60, 46], [100, 44], [100, 72], [88, 68], [75, 58], [62, 52]]},
      {type: "birds", x: 45, y: 3, w: 40, h: 25, intensity: 0.9},
      {type: "smoke", x: 35, y: 0, w: 6, h: 12, intensity: 0.45, wind: 0.4},
      {type: "smoke", x: 94, y: 18, w: 5, h: 12, intensity: 0.3, wind: 0.4}
    ]
  },
  salt_house: {
    image: 'assets/images/scenes/salt_house.jpg',
    exits: {
      the_hythe: {x: 61, y: 50, icon: "↓"},
      packet_stair: {x: 8, y: 55, icon: "←"}
    },
    npcs: {},
    objects: [
      {label: "Asleep on the settle", x: 85, y: 62, act: "search"},
      {label: "Salt-furred wall", x: 14, y: 80, act: "search"},
      {label: "Counter and keys", x: 44, y: 48, act: "search"},
      {label: "Stairs to the beds", x: 28, y: 40, act: "search"}
    ],
    fx: [
      {type: "hearth", x: 70, y: 48, w: 10, h: 16, intensity: 1},
      {type: "candle", x: 15, y: 27, w: 4, h: 9, intensity: 0.8},
      {type: "candle", x: 93, y: 22, w: 4, h: 9, intensity: 0.8},
      {type: "candle", x: 46.5, y: 44, w: 4, h: 8, intensity: 0.7},
      {type: "drip", x: 1, y: 30, w: 10, h: 50, intensity: 0.4, count: 2},
      {type: "dust", x: 30, y: 20, w: 45, h: 45, intensity: 0.25, count: 10}
    ]
  },
  corse_landing: {
    image: 'assets/images/scenes/corse_landing.jpg',
    exits: {
      the_hythe: {x: 50, y: 95, icon: "↑"},
      tally_shed: {x: 39, y: 48, icon: "⌂"},
      factors_house: {x: 8, y: 58, icon: "↑"}
    },
    npcs: {
      gillian_petch: {x: 62, y: 72}
    },
    objects: [
      {label: "Crane", x: 85, y: 38, act: "search"},
      {label: "Square-stacked crates", x: 86, y: 82, act: "search"},
      {label: "Open gate", x: 43, y: 62, act: "search"},
      {label: "Clerk's booth", x: 60, y: 60, act: "search"}
    ],
    fx: [
      {type: "water", x: 57, y: 46, w: 43, h: 24, intensity: 1, count: 36, clip: [[57, 47], [100, 46], [100, 70], [88, 64], [72, 62], [66, 56], [57, 55]]},
      {type: "birds", x: 30, y: 2, w: 60, h: 20, intensity: 0.7},
      {type: "sway", x: 71, y: 38, w: 8.5, h: 21, amp: 1.5, period: 3.6, pivot: [50, 0]},
      {type: "smoke", x: 13, y: -6, w: 6, h: 12, intensity: 0.4, wind: 0.4}
    ]
  },
  tally_shed: {
    image: 'assets/images/scenes/tally_shed.jpg',
    exits: {
      corse_landing: {x: 11, y: 50, icon: "←"}
    },
    npcs: {},
    objects: [
      {label: "The book", x: 13, y: 68, act: "search"},
      {label: "Chain coils", x: 81, y: 85, act: "search"},
      {label: "Lamp oil", x: 32, y: 42, act: "search"},
      {label: "Salt sacks", x: 14, y: 88, act: "search"}
    ],
    fx: [
      {type: "candle", x: 3, y: 57, w: 4, h: 9, intensity: 0.9},
      {type: "dust", x: 30, y: 5, w: 40, h: 60, intensity: 0.6, count: 28, shaft: true, angle: 30}
    ]
  },
  packet_stair: {
    image: 'assets/images/scenes/packet_stair.jpg',
    exits: {
      the_hythe: {x: 95, y: 60, icon: "→"},
      salt_house: {x: 27, y: 44, icon: "↑"},
      brackwater_wharf: {x: 62, y: 80, icon: "↓"}
    },
    npcs: {
      osgar_tull: {x: 28, y: 66}
    },
    objects: [
      {label: "The packet", x: 62, y: 62, act: "search"},
      {label: "Coiled rope", x: 64, y: 82, act: "search"},
      {label: "Green steps", x: 38, y: 66, act: "search"},
      {label: "Downriver", x: 70, y: 38, act: "search"}
    ],
    fx: [
      {type: "water", x: 36, y: 42, w: 64, h: 40, intensity: 1, count: 50, clip: [[36, 46], [100, 42], [100, 76], [78, 82], [58, 82], [48, 76], [42, 64]]},
      {type: "birds", x: 25, y: 8, w: 60, h: 20, intensity: 0.8},
      {type: "sway", x: 56.5, y: 11, w: 4, h: 6, amp: 4, period: 2.6, pivot: [0, 50]}
    ]
  },
  // The same stair while Tull's packet is four days downriver: same camera,
  // the boat painted out. Chosen by the server (location.scene).
  packet_stair_away: {
    image: 'assets/images/scenes/packet_stair_away.jpg',
    exits: {
      the_hythe: {x: 95, y: 60, icon: "→"},
      salt_house: {x: 27, y: 44, icon: "↑"},
      brackwater_wharf: {x: 62, y: 80, icon: "↓"}
    },
    npcs: {},
    objects: [
      {label: "Empty berth", x: 62, y: 62, act: "search"},
      {label: "Coiled rope", x: 64, y: 82, act: "search"},
      {label: "Green steps", x: 38, y: 66, act: "search"},
      {label: "Downriver", x: 70, y: 38, act: "search"}
    ],
    fx: [
      {type: "water", x: 36, y: 42, w: 64, h: 40, intensity: 1, count: 50, clip: [[36, 46], [100, 42], [100, 76], [78, 82], [58, 82], [48, 76], [42, 64]]},
      {type: "birds", x: 25, y: 8, w: 60, h: 20, intensity: 0.8}
    ]
  },
  factors_house: {
    image: 'assets/images/scenes/factors_house.jpg',
    exits: {
      corse_landing: {x: 6, y: 48, icon: "↓"},
      the_long_room: {x: 28, y: 45, icon: "↑"}
    },
    npcs: {
      ludo_corse: {x: 76, y: 52}
    },
    objects: [
      {label: "The empty chair", x: 46, y: 74, act: "search"},
      {label: "View of the landing", x: 85, y: 38, act: "search"},
      {label: "Clock", x: 45, y: 30, act: "search"},
      {label: "Desk", x: 60, y: 60, act: "search"}
    ],
    fx: [
      {type: "candle", x: 53.7, y: 43, w: 4, h: 9, intensity: 0.8},
      {type: "dust", x: 55, y: 10, w: 40, h: 60, intensity: 0.45, count: 20, shaft: true, angle: -25},
      {type: "water", x: 67, y: 40, w: 26, h: 10, intensity: 0.4, count: 8, clip: [[67, 41], [93, 41], [93, 49], [67, 49]]}
    ]
  },
  the_long_room: {
    image: 'assets/images/scenes/the_long_room.jpg',
    exits: {
      factors_house: {x: 11, y: 52, icon: "←"}
    },
    npcs: {},
    objects: [
      {label: "Gimbal lamp", x: 55, y: 62, act: "search"},
      {label: "The presses", x: 88, y: 35, act: "search"},
      {label: "Green table", x: 62, y: 82, act: "search"},
      {label: "Ladder", x: 82, y: 48, act: "search"}
    ],
    fx: [
      {type: "candle", x: 52.5, y: 52, w: 5, h: 10, intensity: 1},
      {type: "candle", x: 35.5, y: 45, w: 4, h: 8, intensity: 0.7},
      {type: "dust", x: 30, y: 30, w: 40, h: 40, intensity: 0.3, count: 12}
    ]
  },
  // ── Quarry Wilds ──
  quarry_road: {
    image: 'assets/images/scenes/quarry_road.jpg',
    exits: {
      east_gate: {x: 11, y: 24, icon: "←"},
      wardens_camp: {x: 16, y: 45, icon: "↑"},
      broken_hills: {x: 60, y: 30, icon: "↑"},
      fighting_pit: {x: 83, y: 64, icon: "↓"},
      boundary_stone: {x: 85, y: 48, icon: "→"},
      caravan_ground: {x: 4, y: 88, icon: "←"}
    },
    npcs: {},
    objects: [
      {label: "Old cart-ruts", x: 48, y: 80, act: "search"},
      {label: "Hawk", x: 27, y: 11, act: "search"}
    ],
    fx: [
      {type: "birds", x: 15, y: 2, w: 50, h: 18, intensity: 0.6},
      {type: "water", x: 30, y: 52, w: 32, h: 46, intensity: 0.5, count: 20, clip: [[42, 52], [50, 52], [62, 98], [30, 98]]},
      {type: "dust", x: 30, y: 40, w: 50, h: 40, intensity: 0.25, count: 12}
    ]
  },
  wardens_camp: {
    image: 'assets/images/scenes/wardens_camp.jpg',
    exits: {
      quarry_road: {x: 88, y: 80, icon: "↓"}
    },
    npcs: {
      ilse: {x: 42, y: 70}
    },
    objects: [
      {label: "Fire-ring", x: 34, y: 80, act: "search"},
      {label: "Tent", x: 16, y: 55, act: "search"},
      {label: "Tin cup", x: 20, y: 53, act: "search"},
      {label: "The valley", x: 65, y: 40, act: "search"}
    ],
    fx: [
      {type: "smoke", x: 30, y: 62, w: 8, h: 16, intensity: 0.15, wind: 0.5},
      {type: "birds", x: 40, y: 5, w: 50, h: 20, intensity: 0.6},
      {type: "sway", x: 18.5, y: 49, w: 3, h: 6, amp: 6, period: 2.4, pivot: [50, 0]},
      {type: "water", x: 53, y: 36, w: 32, h: 20, intensity: 0.5, count: 14, clip: [[53, 38], [62, 36], [72, 44], [85, 52], [80, 56], [66, 48], [55, 42]]}
    ]
  },
  broken_hills: {
    image: 'assets/images/scenes/broken_hills.jpg',
    exits: {
      quarry_road: {x: 4, y: 85, icon: "←"},
      warren_approach: {x: 78, y: 62, icon: "↑"},
      boundary_stone: {x: 18, y: 28, icon: "←"}
    },
    npcs: {},
    objects: [
      {label: "Burned cart", x: 48, y: 28, act: "search"},
      {label: "Crow", x: 55, y: 19, act: "search"}
    ],
    fx: [
      {type: "birds", x: 40, y: 5, w: 50, h: 20, intensity: 0.5},
      {type: "dust", x: 40, y: 50, w: 50, h: 40, intensity: 0.3, count: 12}
    ]
  },
  warren_approach: {
    image: 'assets/images/scenes/warren_approach.jpg',
    exits: {
      broken_hills: {x: 4, y: 80, icon: "←"},
      warren_mouth: {x: 60, y: 55, icon: "⌂"}
    },
    npcs: {},
    objects: [
      {label: "Bone totem", x: 75, y: 50, act: "search"},
      {label: "Sorted bones", x: 68, y: 85, act: "search"},
      {label: "Cart-iron", x: 34, y: 70, act: "search"}
    ],
    fx: [
      {type: "smoke", x: 54, y: -5, w: 12, h: 55, intensity: 0.7, wind: 0.1},
      {type: "birds", x: 0, y: 5, w: 40, h: 20, intensity: 0.4}
    ]
  },
  fighting_pit: {
    image: 'assets/images/scenes/fighting_pit.jpg',
    exits: {
      quarry_road: {x: 19, y: 35, icon: "↑"},
      pit_undercroft: {x: 71, y: 50, icon: "↑"}
    },
    npcs: {
      vessa_dann: {x: 12, y: 58}
    },
    objects: [
      {label: "The crowd", x: 55, y: 10, act: "search"},
      {label: "Raked sand", x: 50, y: 78, act: "search"},
      {label: "Gate counterweight", x: 82, y: 58, act: "search"}
    ],
    fx: [
      {type: "torch", x: 2.5, y: 28, w: 4, h: 9},
      {type: "torch", x: 30.3, y: 35, w: 4, h: 9},
      {type: "torch", x: 45.5, y: 37, w: 4, h: 9},
      {type: "torch", x: 91, y: 33, w: 4, h: 9},
      {type: "dust", x: 30, y: 55, w: 50, h: 35, intensity: 0.4, count: 16}
    ]
  },
  pit_undercroft: {
    image: 'assets/images/scenes/pit_undercroft.jpg',
    exits: {
      fighting_pit: {x: 13, y: 50, icon: "←"}
    },
    npcs: {
      orm_lisser: {x: 55, y: 80}
    },
    objects: [
      {label: "Swept stalls", x: 84, y: 62, act: "search"},
      {label: "Fourth stall", x: 46, y: 55, act: "search"},
      {label: "Boar", x: 62, y: 62, act: "search"},
      {label: "Drain", x: 35, y: 88, act: "search"},
      {label: "Sand through the boards", x: 17, y: 62, act: "search"}
    ],
    fx: [
      {type: "candle", x: 2.5, y: 15, w: 4, h: 10},
      {type: "candle", x: 46, y: 28, w: 4, h: 9},
      {type: "candle", x: 62.5, y: 19, w: 4, h: 9},
      {type: "candle", x: 33.5, y: 36, w: 3, h: 6, intensity: 0.6},
      {type: "drip", x: 16, y: 30, w: 3, h: 45, count: 1, color: "rgb(220,190,130)"},
      {type: "dust", x: 30, y: 40, w: 40, h: 40, intensity: 0.25, count: 10}
    ]
  },
  boundary_stone: {
    image: 'assets/images/scenes/boundary_stone.jpg',
    exits: {
      quarry_road: {x: 8, y: 82, icon: "↓"},
      broken_hills: {x: 95, y: 68, icon: "→"},
      quarrymans_hut: {x: 78, y: 25, icon: "↑"}
    },
    npcs: {
      gest_pyle: {x: 30, y: 70}
    },
    objects: [
      {label: "The mearing stone", x: 44, y: 60, act: "search"},
      {label: "Fresh-cut face", x: 55, y: 60, act: "search"},
      {label: "Fresh stone-dust", x: 50, y: 82, act: "search"},
      {label: "Sheep", x: 61, y: 48, act: "search"}
    ],
    fx: [
      {type: "birds", x: 20, y: 5, w: 60, h: 20, intensity: 0.5}
    ]
  },
  quarrymans_hut: {
    image: 'assets/images/scenes/quarrymans_hut.jpg',
    exits: {
      boundary_stone: {x: 8, y: 70, icon: "←"}
    },
    npcs: {
      dorn_ackley: {x: 62, y: 84}
    },
    objects: [
      {label: "The bench", x: 57, y: 74, act: "search"},
      {label: "Wrapped pick", x: 67, y: 50, act: "search"},
      {label: "Door", x: 55, y: 45, act: "search"},
      {label: "The quarry", x: 88, y: 30, act: "search"}
    ],
    fx: [
      {type: "smoke", x: 64.5, y: 2, w: 6, h: 13, intensity: 0.3, wind: 0.6},
      {type: "birds", x: 10, y: 5, w: 40, h: 20, intensity: 0.4}
    ]
  },
  caravan_ground: {
    image: 'assets/images/scenes/caravan_ground.jpg',
    exits: {
      quarry_road: {x: 92, y: 55, icon: "→"}
    },
    npcs: {
      hesk_dray: {x: 40, y: 74}
    },
    objects: [
      {label: "Laced wagons", x: 42, y: 40, act: "search"},
      {label: "Well", x: 10, y: 65, act: "search"},
      {label: "Picket line", x: 25, y: 55, act: "search"},
      {label: "Small fire", x: 69, y: 47, act: "search"}
    ],
    fx: [
      {type: "fire", x: 66, y: 42, w: 6, h: 8, intensity: 0.6},
      {type: "smoke", x: 66, y: 24, w: 6, h: 20, intensity: 0.3, wind: 0.3},
      {type: "birds", x: 30, y: 5, w: 50, h: 20, intensity: 0.4}
    ]
  },
  // ── Goblin Warren ──
  warren_mouth: {
    image: 'assets/images/scenes/warren_mouth.jpg',
    exits: {
      warren_approach: {x: 17, y: 60, icon: "←"},
      deep_shaft: {x: 47, y: 62, icon: "↓"},
      warren_nests: {x: 81, y: 38, icon: "↑"}
    },
    npcs: {},
    objects: [
      {label: "Spear rack", x: 88, y: 75, act: "search"},
      {label: "Water on the stone", x: 73, y: 55, act: "search"},
      {label: "Pots", x: 8, y: 85, act: "search"}
    ],
    fx: [
      {type: "torch", x: 30.5, y: 32, w: 4, h: 9},
      {type: "torch", x: 91.5, y: 32, w: 4, h: 9},
      {type: "smoke", x: 25, y: 0, w: 50, h: 15, intensity: 0.4, wind: 0.8},
      {type: "drip", x: 70, y: 25, w: 8, h: 45, count: 2},
      {type: "dust", x: 15, y: 40, w: 30, h: 40, intensity: 0.35, count: 12, shaft: true, angle: 15}
    ]
  },
  deep_shaft: {
    image: 'assets/images/scenes/deep_shaft.jpg',
    exits: {
      warren_mouth: {x: 46, y: 15, icon: "↑"},
      warlord_hall: {x: 61, y: 80, icon: "↓"},
      warren_nests: {x: 75, y: 45, icon: "↑"},
      warren_stores: {x: 14, y: 30, icon: "←"},
      broken_floor: {x: 82, y: 62, icon: "↓"}
    },
    npcs: {},
    objects: [
      {label: "Rope-ladder", x: 46, y: 50, act: "search"},
      {label: "The drop", x: 52, y: 92, act: "search"}
    ],
    fx: [
      {type: "torch", x: 4, y: 26, w: 4, h: 9},
      {type: "torch", x: 23, y: 23, w: 4, h: 8},
      {type: "torch", x: 89.5, y: 36, w: 4, h: 9},
      {type: "candle", x: 18, y: 70, w: 4, h: 9},
      {type: "candle", x: 88.5, y: 67, w: 4, h: 9},
      {type: "glow", x: 56, y: 74, w: 10, h: 12, color: "rgb(255,160,80)", period: 4},
      {type: "dust", x: 30, y: 40, w: 40, h: 40, intensity: 0.25, count: 10}
    ]
  },
  warlord_hall: {
    image: 'assets/images/scenes/warlord_hall.jpg',
    exits: {
      deep_shaft: {x: 34, y: 40, icon: "↑"},
      broken_floor: {x: 86, y: 52, icon: "→"}
    },
    npcs: {
      nakka: {x: 64, y: 64}
    },
    objects: [
      {label: "The throne", x: 50, y: 45, act: "search"},
      {label: "Fire-pit", x: 50, y: 82, act: "search"},
      {label: "Trophies", x: 13, y: 20, act: "search"},
      {label: "Plunder", x: 85, y: 82, act: "search"}
    ],
    fx: [
      {type: "fire", x: 45, y: 62, w: 10, h: 20, intensity: 1},
      {type: "glow", x: 43, y: 12, w: 16, h: 30, color: "rgb(120,220,90)", period: 3, intensity: 0.6},
      {type: "dust", x: 20, y: 30, w: 60, h: 40, intensity: 0.3, count: 14}
    ]
  },
  warren_nests: {
    image: 'assets/images/scenes/warren_nests.jpg',
    exits: {
      warren_mouth: {x: 95, y: 18, icon: "↑"},
      deep_shaft: {x: 82, y: 78, icon: "→"}
    },
    npcs: {},
    objects: [
      {label: "Bedding bays", x: 35, y: 35, act: "search"},
      {label: "Lower gallery", x: 40, y: 70, act: "search"},
      {label: "Bone toys", x: 12, y: 92, act: "search"},
      {label: "Brazier", x: 80, y: 52, act: "search"}
    ],
    fx: [
      {type: "candle", x: 3, y: 35, w: 3, h: 6},
      {type: "candle", x: 18, y: 22, w: 3, h: 6},
      {type: "candle", x: 45.5, y: 17, w: 3, h: 6},
      {type: "candle", x: 75.5, y: 13, w: 3, h: 6},
      {type: "candle", x: 31.5, y: 57, w: 3, h: 6},
      {type: "candle", x: 57, y: 47, w: 3, h: 6},
      {type: "candle", x: 4.5, y: 76, w: 3, h: 6},
      {type: "candle", x: 70.5, y: 90, w: 3, h: 6},
      {type: "hearth", x: 78, y: 46, w: 6, h: 9, intensity: 0.7},
      {type: "smoke", x: 20, y: 0, w: 8, h: 18, intensity: 0.3},
      {type: "smoke", x: 52, y: 0, w: 8, h: 18, intensity: 0.3}
    ]
  },
  warren_stores: {
    image: 'assets/images/scenes/warren_stores.jpg',
    exits: {
      deep_shaft: {x: 12, y: 45, icon: "←"}
    },
    npcs: {},
    objects: [
      {label: "Planed crates", x: 75, y: 58, act: "search"},
      {label: "Bar iron", x: 43, y: 66, act: "search"},
      {label: "Salt on battens", x: 36, y: 48, act: "search"},
      {label: "Cut timber", x: 51, y: 45, act: "search"}
    ],
    fx: [
      {type: "candle", x: 31, y: 16, w: 4, h: 12, intensity: 1},
      {type: "dust", x: 25, y: 20, w: 45, h: 50, intensity: 0.35, count: 14}
    ]
  },
  broken_floor: {
    image: 'assets/images/scenes/broken_floor.jpg',
    exits: {
      deep_shaft: {x: 29, y: 30, icon: "↑"},
      warlord_hall: {x: 83, y: 30, icon: "↑"},
      spoil_stair: {x: 57, y: 62, icon: "↓"}
    },
    npcs: {},
    objects: [
      {label: "Square-cut edge", x: 35, y: 62, act: "search"},
      {label: "Props", x: 64, y: 72, act: "search"},
      {label: "The void", x: 47, y: 85, act: "search"}
    ],
    fx: [
      {type: "torch", x: 9.7, y: 16, w: 4, h: 9},
      {type: "torch", x: 41, y: 17, w: 3, h: 7},
      {type: "torch", x: 69, y: 19, w: 3, h: 7},
      {type: "glow", x: 40, y: 70, w: 15, h: 22, color: "rgb(255,190,110)", period: 5},
      {type: "dust", x: 35, y: 45, w: 30, h: 40, intensity: 0.3, count: 12}
    ]
  },
  // ── The Deepworks ──
  spoil_stair: {
    image: 'assets/images/scenes/spoil_stair.jpg',
    exits: {
      deep_shaft: {x: 50, y: 12, icon: "↑"},
      broken_floor: {x: 28, y: 18, icon: "↑"},
      muster_yard: {x: 25, y: 82, icon: "↓"}
    },
    npcs: {},
    objects: [
      {label: "Fire buckets", x: 82, y: 60, act: "search"},
      {label: "New treads", x: 60, y: 55, act: "search"},
      {label: "Rope on pins", x: 82, y: 30, act: "search"}
    ],
    fx: [
      {type: "candle", x: 23, y: 64, w: 3, h: 7},
      {type: "candle", x: 31, y: 61, w: 3, h: 6},
      {type: "candle", x: 45.5, y: 12, w: 3, h: 6},
      {type: "candle", x: 53.5, y: 14, w: 3, h: 6},
      {type: "dust", x: 30, y: 20, w: 40, h: 60, intensity: 0.3, count: 12}
    ]
  },
  muster_yard: {
    image: 'assets/images/scenes/muster_yard.jpg',
    exits: {
      spoil_stair: {x: 37, y: 30, icon: "↑"},
      timekeepers_hut: {x: 10, y: 52, icon: "←"},
      deepworks_canteen: {x: 23, y: 45, icon: "↑"},
      the_headings: {x: 85, y: 45, icon: "→"}
    },
    npcs: {},
    objects: [
      {label: "Bell", x: 56, y: 35, act: "search"},
      {label: "Peg board", x: 66, y: 36, act: "search"},
      {label: "Water butt", x: 92, y: 68, act: "search"},
      {label: "The line", x: 40, y: 76, act: "search"},
      {label: "Benches", x: 50, y: 58, act: "search"}
    ],
    fx: [
      {type: "candle", x: 8, y: 13, w: 4, h: 8},
      {type: "candle", x: 22, y: 27, w: 3, h: 6},
      {type: "candle", x: 55, y: 16, w: 3, h: 7},
      {type: "candle", x: 74, y: 12, w: 3, h: 7},
      {type: "smoke", x: 90, y: 30, w: 10, h: 35, intensity: 0.35, wind: 0.1, color: "#e8e4dc"},
      {type: "dust", x: 20, y: 30, w: 60, h: 40, intensity: 0.3, count: 14}
    ]
  },
  timekeepers_hut: {
    image: 'assets/images/scenes/timekeepers_hut.jpg',
    exits: {
      muster_yard: {x: 14, y: 55, icon: "←"}
    },
    npcs: {
      wenna_coyle: {x: 71, y: 62}
    },
    objects: [
      {label: "Water clock", x: 92, y: 55, act: "search"},
      {label: "Tally rack", x: 75, y: 30, act: "search"},
      {label: "Four ledgers", x: 55, y: 62, act: "search"},
      {label: "Bell rope", x: 60, y: 30, act: "search"},
      {label: "Window on the line", x: 36, y: 40, act: "search"}
    ],
    fx: [
      {type: "candle", x: 28, y: 18, w: 4, h: 9},
      {type: "candle", x: 44.5, y: 21, w: 4, h: 8},
      {type: "sway", x: 55, y: 3, w: 8, h: 40, amp: 0.8, period: 4, pivot: [50, 0]},
      {type: "dust", x: 30, y: 20, w: 40, h: 50, intensity: 0.3, count: 10}
    ]
  },
  deepworks_canteen: {
    image: 'assets/images/scenes/deepworks_canteen.jpg',
    exits: {
      muster_yard: {x: 16, y: 40, icon: "←"}
    },
    npcs: {
      corrin_dale: {x: 48, y: 82}
    },
    objects: [
      {label: "The coppers", x: 62, y: 47, act: "search"},
      {label: "Price board", x: 91, y: 32, act: "search"},
      {label: "Asleep sitting up", x: 85, y: 55, act: "search"}
    ],
    fx: [
      {type: "hearth", x: 52, y: 30, w: 22, h: 22, intensity: 0.8},
      {type: "smoke", x: 54, y: 18, w: 8, h: 22, intensity: 0.5, color: "#e8e4dc"},
      {type: "smoke", x: 64, y: 18, w: 8, h: 22, intensity: 0.5, color: "#e8e4dc"},
      {type: "candle", x: 5, y: 17, w: 4, h: 9},
      {type: "candle", x: 34, y: 18, w: 3, h: 7},
      {type: "candle", x: 81, y: 16, w: 3, h: 7}
    ]
  },
  the_headings: {
    image: 'assets/images/scenes/the_headings.jpg',
    exits: {
      muster_yard: {x: 3, y: 70, icon: "←"},
      pump_gallery: {x: 93, y: 62, icon: "↓"},
      overseers_office: {x: 85, y: 22, icon: "↑"}
    },
    npcs: {},
    objects: [
      {label: "The faces", x: 50, y: 45, act: "search"},
      {label: "Barrow runs", x: 50, y: 72, act: "search"}
    ],
    fx: [
      {type: "candle", x: 35, y: 8, w: 4, h: 9},
      {type: "candle", x: 12.5, y: 11, w: 3, h: 7},
      {type: "candle", x: 65.5, y: 15, w: 3, h: 6},
      {type: "candle", x: 32.5, y: 25, w: 3, h: 6},
      {type: "candle", x: 43, y: 26, w: 3, h: 6},
      {type: "water", x: 70, y: 62, w: 30, h: 38, intensity: 0.8, count: 20, clip: [[92, 62], [98, 66], [90, 85], [78, 98], [70, 98], [84, 80]]},
      {type: "dust", x: 20, y: 30, w: 60, h: 40, intensity: 0.4, count: 18, color: "rgb(210,190,160)"}
    ]
  },
  pump_gallery: {
    image: 'assets/images/scenes/pump_gallery.jpg',
    exits: {
      the_headings: {x: 11, y: 55, icon: "↑"},
      the_undercut: {x: 88, y: 50, icon: "→"}
    },
    npcs: {},
    objects: [
      {label: "Chain-pumps", x: 45, y: 40, act: "search"},
      {label: "The launder", x: 80, y: 72, act: "search"},
      {label: "Caged lamp", x: 67, y: 38, act: "search"}
    ],
    fx: [
      {type: "candle", x: 13.5, y: 22, w: 4, h: 11},
      {type: "candle", x: 37.5, y: 31, w: 4, h: 9},
      {type: "candle", x: 65, y: 28, w: 5, h: 13},
      {type: "sway", x: 62, y: 0, w: 11, h: 47, amp: 1.5, period: 3.2, pivot: [50, 0]},
      {type: "water", x: 55, y: 58, w: 36, h: 15, intensity: 0.7, count: 16, clip: [[55, 61], [90, 66], [89, 72], [55, 66]]},
      {type: "drip", x: 64, y: 45, w: 8, h: 40, count: 3}
    ]
  },
  overseers_office: {
    image: 'assets/images/scenes/overseers_office.jpg',
    exits: {
      the_headings: {x: 12, y: 85, icon: "↓"}
    },
    npcs: {
      bael_rourke: {x: 35, y: 48}
    },
    objects: [
      {label: "Bolted strongbox", x: 48, y: 75, act: "search"},
      {label: "Rolled papers", x: 14, y: 52, act: "search"},
      {label: "Bound volumes", x: 13, y: 15, act: "search"},
      {label: "Coat and good hat", x: 46, y: 35, act: "search"},
      {label: "The works below", x: 81, y: 35, act: "search"},
      {label: "Desk", x: 43, y: 55, act: "search"}
    ],
    fx: [
      {type: "candle", x: 32, y: 17, w: 4, h: 10},
      {type: "dust", x: 25, y: 20, w: 50, h: 50, intensity: 0.3, count: 10}
    ]
  },
  the_undercut: {
    image: 'assets/images/scenes/the_undercut.jpg',
    exits: {
      pump_gallery: {x: 15, y: 35, icon: "←"}
    },
    npcs: {
      fessick: {x: 25, y: 72}
    },
    objects: [
      {label: "The cut face", x: 71, y: 35, act: "search"},
      {label: "Standing water", x: 68, y: 62, act: "search"},
      {label: "Hand tools", x: 49, y: 48, act: "search"},
      {label: "Chalk line", x: 55, y: 75, act: "search"}
    ],
    fx: [
      {type: "candle", x: 36.5, y: 47, w: 4, h: 9},
      {type: "candle", x: 86, y: 67, w: 4, h: 10},
      {type: "drip", x: 60, y: 15, w: 25, h: 45, count: 2},
      {type: "water", x: 55, y: 55, w: 26, h: 15, intensity: 0.4, count: 10, clip: [[55, 56], [80, 55], [81, 66], [68, 70], [55, 62]]},
      {type: "glow", x: 62, y: 20, w: 20, h: 30, color: "rgb(150,180,255)", period: 6, intensity: 0.4}
    ]
  },
  // ── Ford Road ──
  ford_lane: {
    image: 'assets/images/scenes/ford_lane.jpg',
    exits: {
      west_gate: {x: 50, y: 95, icon: "↓"},
      the_ford: {x: 47, y: 48, icon: "↑"},
      drovers_rest: {x: 25, y: 62, icon: "↓"}
    },
    npcs: {},
    objects: [
      {label: "Milestone", x: 58, y: 72, act: "search"},
      {label: "Ditch", x: 72, y: 88, act: "search"},
      {label: "Hedge", x: 17, y: 35, act: "search"}
    ],
    fx: [
      {type: "birds", x: 20, y: 5, w: 60, h: 20, intensity: 0.6},
      {type: "water", x: 62, y: 74, w: 23, h: 26, intensity: 0.5, count: 10, clip: [[62, 74], [68, 74], [85, 100], [75, 100]]}
    ]
  },
  the_ford: {
    image: 'assets/images/scenes/the_ford.jpg',
    exits: {
      ford_lane: {x: 97, y: 85, icon: "→"},
      mill_ruin: {x: 32, y: 25, icon: "↑"},
      priory_track: {x: 70, y: 25, icon: "↑"},
      harrow_steading: {x: 12, y: 35, icon: "←"}
    },
    npcs: {
      joss_frayle: {x: 57, y: 86}
    },
    objects: [
      {label: "Standing stone", x: 46, y: 72, act: "search"},
      {label: "Mounting-block", x: 24, y: 72, act: "search"},
      {label: "Four bare patches", x: 78, y: 45, act: "search"},
      {label: "Rope stay", x: 28, y: 58, act: "search"}
    ],
    fx: [
      {type: "water", x: 0, y: 35, w: 100, h: 65, intensity: 1, count: 60, clip: [[0, 38], [20, 35], [50, 48], [100, 58], [100, 100], [62, 100], [55, 88], [52, 72], [40, 62], [20, 58], [0, 56]]},
      {type: "birds", x: 30, y: 2, w: 60, h: 18, intensity: 0.5}
    ]
  },
  mill_ruin: {
    image: 'assets/images/scenes/mill_ruin.jpg',
    exits: {
      the_ford: {x: 90, y: 62, icon: "↓"}
    },
    npcs: {
      wick_lorrimer: {x: 66, y: 88}
    },
    objects: [
      {label: "The race", x: 63, y: 75, act: "search"},
      {label: "Wheel-pit", x: 30, y: 75, act: "search"},
      {label: "Cook pot", x: 53, y: 80, act: "search"},
      {label: "Charred beams", x: 15, y: 55, act: "search"}
    ],
    fx: [
      {type: "water", x: 52, y: 64, w: 22, h: 22, intensity: 1, count: 20, clip: [[52, 64], [70, 64], [74, 84], [60, 86]]},
      {type: "water", x: 22, y: 68, w: 22, h: 17, intensity: 0.3, count: 8, clip: [[22, 68], [42, 70], [44, 85], [22, 85]]},
      {type: "birds", x: 50, y: 2, w: 45, h: 18, intensity: 0.4}
    ]
  },
  priory_track: {
    image: 'assets/images/scenes/priory_track.jpg',
    exits: {
      the_ford: {x: 10, y: 70, icon: "↓"},
      priory_gate: {x: 68, y: 22, icon: "↑"}
    },
    npcs: {},
    objects: [
      {label: "Broken cross-stone", x: 34, y: 45, act: "search"},
      {label: "Cracked flags", x: 60, y: 80, act: "search"}
    ],
    fx: [
      {type: "birds", x: 40, y: 2, w: 55, h: 20, intensity: 0.5},
      {type: "water", x: 0, y: 52, w: 25, h: 18, intensity: 0.5, count: 10, clip: [[0, 55], [24, 52], [22, 60], [0, 70]]}
    ]
  },
  harrow_steading: {
    image: 'assets/images/scenes/harrow_steading.jpg',
    exits: {
      the_ford: {x: 93, y: 45, icon: "→"},
      drovers_rest: {x: 90, y: 86, icon: "→"},
      fen_road_head: {x: 6, y: 58, icon: "←"}
    },
    npcs: {
      sten_harrow: {x: 50, y: 64}
    },
    objects: [
      {label: "Porch lamp", x: 46, y: 52, act: "search"},
      {label: "The dog", x: 24, y: 82, act: "search"},
      {label: "Fleece", x: 63, y: 57, act: "search"},
      {label: "Gate", x: 45, y: 78, act: "search"}
    ],
    fx: [
      {type: "smoke", x: 48.5, y: 2, w: 5, h: 13, intensity: 0.4, wind: 0.4},
      {type: "fog", x: 0, y: 28, w: 30, h: 20, intensity: 0.5, wind: 0.2},
      {type: "water", x: 83, y: 46, w: 17, h: 14, intensity: 0.5, count: 8, clip: [[83, 46], [100, 50], [100, 60], [90, 55]]}
    ]
  },
  drovers_rest: {
    image: 'assets/images/scenes/drovers_rest.jpg',
    exits: {
      ford_lane: {x: 12, y: 33, icon: "↑"},
      harrow_steading: {x: 3, y: 70, icon: "←"},
      winter_field: {x: 95, y: 88, icon: "→"}
    },
    npcs: {
      tull_barrow: {x: 60, y: 86}
    },
    objects: [
      {label: "The fold", x: 55, y: 55, act: "search"},
      {label: "Trough", x: 45, y: 68, act: "search"},
      {label: "Lantern pole", x: 48, y: 25, act: "search"},
      {label: "Lean-to", x: 32, y: 35, act: "search"}
    ],
    fx: [
      {type: "fire", x: 15.5, y: 72, w: 6, h: 9, intensity: 0.8},
      {type: "fire", x: 81.5, y: 71, w: 6, h: 9, intensity: 0.8},
      {type: "hearth", x: 31.5, y: 37, w: 6, h: 10, intensity: 0.7},
      {type: "candle", x: 49.5, y: 21, w: 3, h: 7},
      {type: "smoke", x: 16, y: 55, w: 6, h: 20, intensity: 0.3},
      {type: "smoke", x: 82, y: 55, w: 6, h: 20, intensity: 0.3},
      {type: "water", x: 40, y: 62, w: 10, h: 6, intensity: 0.5, count: 6}
    ]
  },
  winter_field: {
    image: 'assets/images/scenes/winter_field.jpg',
    exits: {
      drovers_rest: {x: 12, y: 28, icon: "←"},
      fen_road_head: {x: 97, y: 55, icon: "→"}
    },
    npcs: {},
    objects: [
      {label: "The herd", x: 75, y: 33, act: "search"},
      {label: "Thorn brake", x: 62, y: 20, act: "search"},
      {label: "Standing water", x: 50, y: 58, act: "search"}
    ],
    fx: [
      {type: "water", x: 20, y: 42, w: 70, h: 33, intensity: 0.8, count: 30, clip: [[20, 45], [60, 42], [90, 60], [75, 75], [45, 70], [30, 60]]},
      {type: "birds", x: 0, y: 5, w: 50, h: 15, intensity: 0.5},
      {type: "fog", x: 0, y: 15, w: 100, h: 15, intensity: 0.3, wind: 0.5}
    ]
  },
  fen_road_head: {
    image: 'assets/images/scenes/fen_road_head.jpg',
    exits: {
      harrow_steading: {x: 95, y: 48, icon: "→"},
      winter_field: {x: 85, y: 30, icon: "→"},
      fen_causeway: {x: 25, y: 40, icon: "↑"}
    },
    npcs: {},
    objects: [
      {label: "Horn on a chain", x: 68, y: 72, act: "search"},
      {label: "New faggots", x: 45, y: 58, act: "search"},
      {label: "Frogs", x: 14, y: 72, act: "search"}
    ],
    fx: [
      {type: "fog", x: 0, y: 18, w: 100, h: 22, intensity: 0.7, wind: 0.2},
      {type: "water", x: 0, y: 38, w: 80, h: 45, intensity: 0.7, count: 30, clip: [[0, 42], [12, 40], [25, 60], [40, 50], [70, 40], [80, 50], [60, 60], [40, 68], [20, 78], [0, 80]]},
      {type: "birds", x: 30, y: 5, w: 50, h: 15, intensity: 0.4}
    ]
  },
  // ── Brackwater (Act II) ──
  brackwater_wharf: {
    image: 'assets/images/scenes/brackwater_wharf.jpg',
    subtitle: 'four days below Greyhythe',
    exits: {
      the_yard: {x: 6, y: 50, icon: "←"},
      house_street: {x: 30, y: 24, icon: "↑"},
      the_fortieth: {x: 56, y: 30, icon: "⌂"},
      the_mudside: {x: 90, y: 86, icon: "↓"},
      brindles_chandlery: {x: 72, y: 56, icon: "⌂"},
      packet_stair: {x: 47, y: 80, icon: "↩"}
    },
    npcs: {},
    objects: [
      {label: "The grain hulk", x: 36, y: 44, act: "search"},
      {label: "A crane and its net", x: 22, y: 30, act: "search"}
    ],
    fx: [
      {type: "water", x: 0, y: 62, w: 100, h: 38, intensity: 0.8, count: 34},
      {type: "birds", x: 50, y: 4, w: 45, h: 20, intensity: 0.7},
      {type: "smoke", x: 2, y: 0, w: 5, h: 10, intensity: 0.35, wind: 0.5}
    ]
  },
  the_yard: {
    image: 'assets/images/scenes/the_yard.jpg',
    subtitle: 'every manifest goes through the window',
    exits: {
      brackwater_wharf: {x: 50, y: 90, icon: "↓"}
    },
    npcs: {
      abel_moss: {x: 44, y: 62}
    },
    objects: [
      {label: "The mark over the gate", x: 50, y: 12, act: "search"},
      {label: "Greyhythe grain sacks", x: 62, y: 76, act: "search"}
    ],
    fx: [
      {type: "dust", x: 35, y: 55, w: 30, h: 30, intensity: 0.25, count: 8}
    ]
  },
  house_street: {
    image: 'assets/images/scenes/house_street.jpg',
    subtitle: 'thirty doors, one with no plate',
    exits: {
      brackwater_wharf: {x: 18, y: 90, icon: "↓"},
      number_nine: {x: 63, y: 44, icon: "↑"},
      record_office: {x: 88, y: 56, icon: "⌂"}
    },
    npcs: {},
    objects: [
      {label: "A brass plate", x: 7, y: 40, act: "search"},
      {label: "The pump", x: 62, y: 56, act: "search"}
    ],
    fx: [
      {type: "smoke", x: 35, y: 0, w: 5, h: 10, intensity: 0.3, wind: 0.4},
      {type: "birds", x: 50, y: 2, w: 30, h: 15, intensity: 0.4}
    ]
  },
  number_nine: {
    image: 'assets/images/scenes/number_nine.jpg',
    subtitle: 'eleven chairs',
    exits: {
      house_street: {x: 31, y: 44, icon: "↓"}
    },
    npcs: {
      minna_crale: {x: 18, y: 78},
      hollin_sare: {x: 50, y: 50}
    },
    objects: [
      {label: "The clock", x: 50, y: 24, act: "search"},
      {label: "The articles, framed", x: 62, y: 32, act: "search"},
      {label: "Spectacles on the minutes", x: 60, y: 62, act: "search"}
    ],
    fx: [
      {type: "hearth", x: 10, y: 58, w: 9, h: 16, intensity: 1},
      {type: "dust", x: 80, y: 20, w: 18, h: 40, intensity: 0.25, count: 8}
    ]
  },
  record_office: {
    image: 'assets/images/scenes/record_office.jpg',
    subtitle: 'a penny a page',
    exits: {
      house_street: {x: 5, y: 62, icon: "←"}
    },
    npcs: {
      dorcas_fenn: {x: 64, y: 58}
    },
    objects: [
      {label: "Field books in green cloth", x: 42, y: 66, act: "search"},
      {label: "The ladder on its rail", x: 63, y: 32, act: "search"}
    ],
    fx: [
      {type: "dust", x: 25, y: 5, w: 25, h: 55, intensity: 0.5, count: 16},
      {type: "hearth", x: 8, y: 80, w: 6, h: 10, intensity: 0.7}
    ]
  },
  the_fortieth: {
    image: 'assets/images/scenes/the_fortieth.jpg',
    subtitle: 'a fire at each end',
    exits: {
      brackwater_wharf: {x: 30, y: 40, icon: "↩"}
    },
    npcs: {
      jory_kemp: {x: 60, y: 40},
      mr_pellow: {x: 18, y: 58},
      pyke_downriver: {x: 86, y: 58}
    },
    objects: [
      {label: "The tide board", x: 63, y: 24, act: "search"}
    ],
    fx: [
      {type: "hearth", x: 11, y: 46, w: 9, h: 16, intensity: 1},
      {type: "hearth", x: 84, y: 48, w: 9, h: 15, intensity: 1},
      {type: "candle", x: 34, y: 30, w: 4, h: 8, intensity: 0.6}
    ]
  },
  the_mudside: {
    image: 'assets/images/scenes/the_mudside.jpg',
    subtitle: 'where the stones stop',
    exits: {
      brackwater_wharf: {x: 14, y: 76, icon: "↑"}
    },
    npcs: {
      coll_darrow: {x: 60, y: 52},
      lise_fenner: {x: 78, y: 58}
    },
    objects: [
      {label: "A woman mending a net", x: 41, y: 55, act: "search"},
      {label: "A hulk, slowly becoming mud", x: 57, y: 41, act: "search"}
    ],
    fx: [
      {type: "fire", x: 26, y: 56, w: 4, h: 8, intensity: 0.8},
      {type: "fire", x: 82, y: 45, w: 3, h: 6, intensity: 0.6},
      {type: "smoke", x: 25, y: 35, w: 5, h: 20, intensity: 0.4, wind: 0.5},
      {type: "water", x: 50, y: 55, w: 50, h: 40, intensity: 0.5, count: 18}
    ]
  },
  uv_camp: {
    "image": "assets/images/scenes/uv_camp.jpg",
    "subtitle": "the fire that never goes out",
    "exits": {
      "uv_spoil": {
        "x": 14,
        "y": 90,
        "icon": "↑"
      },
      "uv_bunkhouse": {
        "x": 50,
        "y": 90,
        "icon": "⌂"
      },
      "uv_mouth": {
        "x": 86,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The communal fire",
        "x": 50,
        "y": 62,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "smoke",
        "x": 10,
        "y": 0,
        "w": 12,
        "h": 20,
        "intensity": 0.35,
        "wind": 0.5
      }
    ]
  },
  uv_bunkhouse: {
    "image": "assets/images/scenes/uv_bunkhouse.jpg",
    "subtitle": "three coppers a bunk",
    "exits": {
      "uv_camp": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The stove at the end",
        "x": 50,
        "y": 50,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  uv_spoil: {
    "image": "assets/images/scenes/uv_spoil.jpg",
    "subtitle": "everything the dark gave back",
    "exits": {
      "uv_camp": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "Fresh spoil",
        "x": 45,
        "y": 60,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  uv_mouth: {
    "image": "assets/images/scenes/uv_mouth.jpg",
    "subtitle": "cut, not natural",
    "exits": {
      "uv_camp": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The lintel",
        "x": 50,
        "y": 22,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  waermans_rest: {
    "image": "assets/images/scenes/waermans_rest.jpg",
    "subtitle": "news arrives here first",
    "exits": {
      "hallowmarket": {
        "x": 14,
        "y": 90,
        "icon": "⌂"
      },
      "the_rest_cellar": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      },
      "the_hallowgate": {
        "x": 86,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The ox teams",
        "x": 60,
        "y": 72,
        "act": "search"
      },
      {
        "label": "The inn sign",
        "x": 30,
        "y": 26,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "smoke",
        "x": 10,
        "y": 0,
        "w": 12,
        "h": 20,
        "intensity": 0.35,
        "wind": 0.5
      }
    ]
  },
  the_rest_cellar: {
    "image": "assets/images/scenes/the_rest_cellar.jpg",
    "subtitle": "the grate has been moved",
    "exits": {
      "waermans_rest": {
        "x": 25,
        "y": 90,
        "icon": "↑"
      },
      "underquay_stair": {
        "x": 75,
        "y": 90,
        "icon": "⌂"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The drain grate",
        "x": 75,
        "y": 82,
        "act": "search"
      },
      {
        "label": "The barrels",
        "x": 44,
        "y": 60,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  hallowmarket: {
    "image": "assets/images/scenes/hallowmarket.jpg",
    "subtitle": "anything but a straight answer",
    "exits": {
      "boomhead": {
        "x": 14,
        "y": 64,
        "icon": "←"
      },
      "waermans_rest": {
        "x": 50,
        "y": 64,
        "icon": "⌂"
      },
      "halvards_ironmongery": {
        "x": 86,
        "y": 64,
        "icon": "⌂"
      },
      "the_hallowgate": {
        "x": 14,
        "y": 77,
        "icon": "↓"
      },
      "the_great_slip": {
        "x": 50,
        "y": 77,
        "icon": "↓"
      },
      "hallowgate_keep": {
        "x": 86,
        "y": 77,
        "icon": "→"
      },
      "the_weigh_house": {
        "x": 14,
        "y": 90,
        "icon": "←"
      },
      "league_granary": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      },
      "the_tar_steps": {
        "x": 86,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The rope stall",
        "x": 14,
        "y": 62,
        "act": "search"
      },
      {
        "label": "A cart of goods",
        "x": 66,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  the_hallowgate: {
    "image": "assets/images/scenes/the_hallowgate.jpg",
    "subtitle": "there is no road north",
    "exits": {
      "hallowgate_keep": {
        "x": 14,
        "y": 77,
        "icon": "↑"
      },
      "hallowmarket": {
        "x": 50,
        "y": 77,
        "icon": "⌂"
      },
      "wardens_road": {
        "x": 86,
        "y": 77,
        "icon": "⌂"
      },
      "waermans_rest": {
        "x": 14,
        "y": 90,
        "icon": "←"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The tally-clerk's hut",
        "x": 62,
        "y": 30,
        "act": "search"
      },
      {
        "label": "The portcullis",
        "x": 64,
        "y": 42,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  hallowgate_keep: {
    "image": "assets/images/scenes/hallowgate_keep.jpg",
    "subtitle": "forty on the roll, four here",
    "exits": {
      "hallowmarket": {
        "x": 25,
        "y": 90,
        "icon": "⌂"
      },
      "the_hallowgate": {
        "x": 75,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The duty board",
        "x": 21,
        "y": 45,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  league_granary: {
    "image": "assets/images/scenes/league_granary.jpg",
    "subtitle": "full from the doorway",
    "exits": {
      "hallowmarket": {
        "x": 25,
        "y": 90,
        "icon": "↓"
      },
      "the_weigh_house": {
        "x": 75,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The far bays",
        "x": 52,
        "y": 45,
        "act": "search"
      },
      {
        "label": "The League seals",
        "x": 6,
        "y": 48,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_weigh_house: {
    "image": "assets/images/scenes/the_weigh_house.jpg",
    "subtitle": "ninety years of ledgers",
    "exits": {
      "hallowmarket": {
        "x": 25,
        "y": 90,
        "icon": "↓"
      },
      "league_granary": {
        "x": 75,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The box on the shelf",
        "x": 87,
        "y": 15,
        "act": "search"
      },
      {
        "label": "The beam-scale",
        "x": 48,
        "y": 25,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  halvards_ironmongery: {
    "image": "assets/images/scenes/halvards_ironmongery.jpg",
    "subtitle": "every fastening in the city",
    "exits": {
      "hallowmarket": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The bundle of letters",
        "x": 27,
        "y": 28,
        "act": "search"
      },
      {
        "label": "Nails in ninety sizes",
        "x": 52,
        "y": 82,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  moot_yard: {
    "image": "assets/images/scenes/moot_yard.jpg",
    "subtitle": "everyone is seen coming up",
    "exits": {
      "boomhead": {
        "x": 14,
        "y": 64,
        "icon": "←"
      },
      "the_factors_house": {
        "x": 50,
        "y": 64,
        "icon": "↑"
      },
      "shipwrights_moot": {
        "x": 86,
        "y": 64,
        "icon": "⌂"
      },
      "venniks": {
        "x": 14,
        "y": 77,
        "icon": "⌂"
      },
      "the_sounding_line": {
        "x": 50,
        "y": 77,
        "icon": "⌂"
      },
      "old_waer_square": {
        "x": 86,
        "y": 77,
        "icon": "⌂"
      },
      "the_wet_hall": {
        "x": 14,
        "y": 90,
        "icon": "↓"
      },
      "the_charthouse": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      },
      "the_tar_steps": {
        "x": 86,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The parapet",
        "x": 22,
        "y": 52,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  shipwrights_moot: {
    "image": "assets/images/scenes/shipwrights_moot.jpg",
    "subtitle": "the hull that is never finished",
    "exits": {
      "moot_yard": {
        "x": 50,
        "y": 90,
        "icon": "⌂"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The hull frame",
        "x": 55,
        "y": 42,
        "act": "search"
      },
      {
        "label": "The worn benches",
        "x": 80,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_factors_house: {
    "image": "assets/images/scenes/the_factors_house.jpg",
    "subtitle": "imported stone, local staff",
    "exits": {
      "moot_yard": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The strongroom door",
        "x": 10,
        "y": 50,
        "act": "search"
      },
      {
        "label": "The ledger on the desk",
        "x": 59,
        "y": 62,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_wet_hall: {
    "image": "assets/images/scenes/the_wet_hall.jpg",
    "subtitle": "floods twice a day",
    "exits": {
      "moot_yard": {
        "x": 50,
        "y": 90,
        "icon": "↑"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The balcony over the water",
        "x": 56,
        "y": 42,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "water",
        "x": 0,
        "y": 70,
        "w": 100,
        "h": 30,
        "intensity": 0.5,
        "count": 20
      }
    ]
  },
  the_charthouse: {
    "image": "assets/images/scenes/the_charthouse.jpg",
    "subtitle": "the presses nobody opens",
    "exits": {
      "moot_yard": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "A drawer that shuts too easily",
        "x": 58,
        "y": 52,
        "act": "search"
      },
      {
        "label": "The chart table",
        "x": 34,
        "y": 78,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_sounding_line: {
    "image": "assets/images/scenes/the_sounding_line.jpg",
    "subtitle": "charts change hands upstairs",
    "exits": {
      "moot_yard": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The stair to the upper rooms",
        "x": 52,
        "y": 30,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  boomhead: {
    "image": "assets/images/scenes/boomhead.jpg",
    "subtitle": "every cargo is set down here",
    "exits": {
      "old_waer_square": {
        "x": 14,
        "y": 64,
        "icon": "↑"
      },
      "ilbers_chandlery": {
        "x": 50,
        "y": 64,
        "icon": "⌂"
      },
      "the_drowned_jarl": {
        "x": 86,
        "y": 64,
        "icon": "⌂"
      },
      "the_great_crane": {
        "x": 14,
        "y": 77,
        "icon": "↓"
      },
      "hallowmarket": {
        "x": 50,
        "y": 77,
        "icon": "↓"
      },
      "the_great_slip": {
        "x": 86,
        "y": 77,
        "icon": "↓"
      },
      "the_counting_boom": {
        "x": 14,
        "y": 90,
        "icon": "←"
      },
      "the_chain_winch": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      },
      "moot_yard": {
        "x": 86,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The chain across the mouth",
        "x": 48,
        "y": 36,
        "act": "search"
      },
      {
        "label": "Barrels nobody counted",
        "x": 70,
        "y": 84,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "water",
        "x": 0,
        "y": 70,
        "w": 100,
        "h": 30,
        "intensity": 0.5,
        "count": 20
      }
    ]
  },
  venniks: {
    "image": "assets/images/scenes/venniks.jpg",
    "subtitle": "he has begun leaving things out",
    "exits": {
      "moot_yard": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The letter being sealed",
        "x": 45,
        "y": 63,
        "act": "search"
      },
      {
        "label": "The little balance",
        "x": 90,
        "y": 70,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_counting_boom: {
    "image": "assets/images/scenes/the_counting_boom.jpg",
    "subtitle": "three locks, three keys",
    "exits": {
      "boomhead": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The iron box",
        "x": 93,
        "y": 40,
        "act": "search"
      },
      {
        "label": "The weighing plate",
        "x": 50,
        "y": 58,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_chain_winch: {
    "image": "assets/images/scenes/the_chain_winch.jpg",
    "subtitle": "eleven men, four minutes",
    "exits": {
      "boomhead": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The ratchet",
        "x": 50,
        "y": 48,
        "act": "search"
      },
      {
        "label": "The bell rope",
        "x": 67,
        "y": 25,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "water",
        "x": 0,
        "y": 72,
        "w": 100,
        "h": 28,
        "intensity": 0.4,
        "count": 14
      },
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  ilbers_chandlery: {
    "image": "assets/images/scenes/ilbers_chandlery.jpg",
    "subtitle": "credit, sold on",
    "exits": {
      "boomhead": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "Ilber's ledger",
        "x": 44,
        "y": 52,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_drowned_jarl: {
    "image": "assets/images/scenes/the_drowned_jarl.jpg",
    "subtitle": "no questions asked",
    "exits": {
      "boomhead": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The fire trench",
        "x": 50,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_great_crane: {
    "image": "assets/images/scenes/the_great_crane.jpg",
    "subtitle": "in the air a long time",
    "exits": {
      "boomhead": {
        "x": 14,
        "y": 90,
        "icon": "↩"
      },
      "the_quiet_charter": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      },
      "underquay_stair": {
        "x": 86,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The treadwheel",
        "x": 77,
        "y": 40,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "water",
        "x": 0,
        "y": 70,
        "w": 100,
        "h": 30,
        "intensity": 0.5,
        "count": 20
      }
    ]
  },
  the_quiet_charter: {
    "image": "assets/images/scenes/the_quiet_charter.jpg",
    "subtitle": "not a guild, an arrangement",
    "exits": {
      "the_great_crane": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The ledger",
        "x": 40,
        "y": 56,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_tar_steps: {
    "image": "assets/images/scenes/the_tar_steps.jpg",
    "subtitle": "black underfoot all the way",
    "exits": {
      "moot_yard": {
        "x": 14,
        "y": 64,
        "icon": "↑"
      },
      "the_pitch_yards": {
        "x": 50,
        "y": 64,
        "icon": "⌂"
      },
      "the_pitch_and_pot": {
        "x": 86,
        "y": 64,
        "icon": "⌂"
      },
      "hallowmarket": {
        "x": 14,
        "y": 77,
        "icon": "↓"
      },
      "the_oakum_house": {
        "x": 50,
        "y": 77,
        "icon": "↓"
      },
      "the_boom_forge": {
        "x": 86,
        "y": 77,
        "icon": "→"
      },
      "the_tar_gaol": {
        "x": 14,
        "y": 90,
        "icon": "←"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The smoke below",
        "x": 18,
        "y": 50,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "smoke",
        "x": 10,
        "y": 0,
        "w": 12,
        "h": 20,
        "intensity": 0.35,
        "wind": 0.5
      }
    ]
  },
  the_boom_forge: {
    "image": "assets/images/scenes/the_boom_forge.jpg",
    "subtitle": "what is behind the screen",
    "exits": {
      "the_tar_steps": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The canvas screen",
        "x": 70,
        "y": 45,
        "act": "search"
      },
      {
        "label": "The chain links",
        "x": 30,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      },
      {
        "type": "glow",
        "x": 0,
        "y": 40,
        "w": 25,
        "h": 30,
        "intensity": 0.5
      }
    ]
  },
  the_oakum_house: {
    "image": "assets/images/scenes/the_oakum_house.jpg",
    "subtitle": "paid in bread",
    "exits": {
      "the_tar_steps": {
        "x": 50,
        "y": 90,
        "icon": "↑"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The long bench",
        "x": 50,
        "y": 60,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_pitch_yards: {
    "image": "assets/images/scenes/the_pitch_yards.jpg",
    "subtitle": "a closed shop",
    "exits": {
      "the_tar_steps": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "A cauldron of pitch",
        "x": 27,
        "y": 68,
        "act": "search"
      },
      {
        "label": "The kilns",
        "x": 88,
        "y": 60,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "smoke",
        "x": 10,
        "y": 0,
        "w": 12,
        "h": 20,
        "intensity": 0.35,
        "wind": 0.5
      }
    ]
  },
  the_pitch_and_pot: {
    "image": "assets/images/scenes/the_pitch_and_pot.jpg",
    "subtitle": "name the thing at the bar",
    "exits": {
      "the_tar_steps": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The bar",
        "x": 80,
        "y": 60,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_tar_gaol: {
    "image": "assets/images/scenes/the_tar_gaol.jpg",
    "subtitle": "on an authority nobody has produced",
    "exits": {
      "the_tar_steps": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The cells",
        "x": 80,
        "y": 45,
        "act": "search"
      },
      {
        "label": "The barred window",
        "x": 35,
        "y": 36,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_great_slip: {
    "image": "assets/images/scenes/the_great_slip.jpg",
    "subtitle": "every hull a little wrong",
    "exits": {
      "the_sailmakers_loft": {
        "x": 14,
        "y": 64,
        "icon": "↑"
      },
      "boomhead": {
        "x": 50,
        "y": 64,
        "icon": "↑"
      },
      "the_nine_masts": {
        "x": 86,
        "y": 64,
        "icon": "⌂"
      },
      "the_dry_dock": {
        "x": 14,
        "y": 77,
        "icon": "↓"
      },
      "the_mast_pond": {
        "x": 50,
        "y": 77,
        "icon": "↓"
      },
      "the_ropehouse": {
        "x": 86,
        "y": 77,
        "icon": "→"
      },
      "hallowmarket": {
        "x": 14,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The chalked gable",
        "x": 15,
        "y": 35,
        "act": "search"
      },
      {
        "label": "A hull on the ways",
        "x": 30,
        "y": 62,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "smoke",
        "x": 10,
        "y": 0,
        "w": 12,
        "h": 20,
        "intensity": 0.35,
        "wind": 0.5
      }
    ]
  },
  the_nine_masts: {
    "image": "assets/images/scenes/the_nine_masts.jpg",
    "subtitle": "count the masts on the beam",
    "exits": {
      "the_great_slip": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The masts on the beam",
        "x": 50,
        "y": 16,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_mast_pond: {
    "image": "assets/images/scenes/the_mast_pond.jpg",
    "subtitle": "nine short",
    "exits": {
      "the_great_slip": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The tally-board",
        "x": 34,
        "y": 60,
        "act": "search"
      },
      {
        "label": "Seasoning pines",
        "x": 60,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "water",
        "x": 0,
        "y": 70,
        "w": 100,
        "h": 30,
        "intensity": 0.5,
        "count": 20
      }
    ]
  },
  the_ropehouse: {
    "image": "assets/images/scenes/the_ropehouse.jpg",
    "subtitle": "nobody can overhear",
    "exits": {
      "the_great_slip": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The far end of the walk",
        "x": 62,
        "y": 36,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_sailmakers_loft: {
    "image": "assets/images/scenes/the_sailmakers_loft.jpg",
    "subtitle": "they miss nothing",
    "exits": {
      "the_great_slip": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The quay below",
        "x": 20,
        "y": 60,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_dry_dock: {
    "image": "assets/images/scenes/the_dry_dock.jpg",
    "subtitle": "made good by nobody's order",
    "exits": {
      "the_great_slip": {
        "x": 50,
        "y": 90,
        "icon": "↑"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The Drennmark boat",
        "x": 40,
        "y": 68,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  old_waer_square: {
    "image": "assets/images/scenes/old_waer_square.jpg",
    "subtitle": "six houses that matter",
    "exits": {
      "the_waer_light": {
        "x": 14,
        "y": 77,
        "icon": "↑"
      },
      "the_cold_anchor": {
        "x": 50,
        "y": 77,
        "icon": "⌂"
      },
      "temple_of_the_deep_mere": {
        "x": 86,
        "y": 77,
        "icon": "↓"
      },
      "moot_yard": {
        "x": 14,
        "y": 90,
        "icon": "↓"
      },
      "boomhead": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      },
      "the_fish_hall": {
        "x": 86,
        "y": 90,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The well",
        "x": 48,
        "y": 62,
        "act": "search"
      },
      {
        "label": "A family mark",
        "x": 15,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  the_cold_anchor: {
    "image": "assets/images/scenes/the_cold_anchor.jpg",
    "subtitle": "Waerfolk only, in practice",
    "exits": {
      "old_waer_square": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The anchor stock",
        "x": 68,
        "y": 30,
        "act": "search"
      },
      {
        "label": "The oldest woman",
        "x": 42,
        "y": 58,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_fish_hall: {
    "image": "assets/images/scenes/the_fish_hall.jpg",
    "subtitle": "settled before noon, loudly",
    "exits": {
      "old_waer_square": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The night's catch",
        "x": 57,
        "y": 62,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "water",
        "x": 0,
        "y": 70,
        "w": 100,
        "h": 30,
        "intensity": 0.5,
        "count": 20
      }
    ]
  },
  temple_of_the_deep_mere: {
    "image": "assets/images/scenes/temple_of_the_deep_mere.jpg",
    "subtitle": "offerings are not expected back",
    "exits": {
      "old_waer_square": {
        "x": 50,
        "y": 90,
        "icon": "↑"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The rail over the water",
        "x": 68,
        "y": 58,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "water",
        "x": 0,
        "y": 70,
        "w": 100,
        "h": 30,
        "intensity": 0.5,
        "count": 20
      }
    ]
  },
  the_waer_light: {
    "image": "assets/images/scenes/the_waer_light.jpg",
    "subtitle": "four dark nights this spring",
    "exits": {
      "old_waer_square": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The lantern",
        "x": 43,
        "y": 30,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "water",
        "x": 0,
        "y": 70,
        "w": 100,
        "h": 30,
        "intensity": 0.5,
        "count": 20
      }
    ]
  },
  underquay_stair: {
    "image": "assets/images/scenes/underquay_stair.jpg",
    "subtitle": "two tide-marks",
    "exits": {
      "the_great_crane": {
        "x": 14,
        "y": 90,
        "icon": "↑"
      },
      "the_rest_cellar": {
        "x": 50,
        "y": 90,
        "icon": "⌂"
      },
      "the_low_cellars": {
        "x": 86,
        "y": 90,
        "icon": "⌂"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The new iron rings",
        "x": 80,
        "y": 48,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "water",
        "x": 0,
        "y": 72,
        "w": 100,
        "h": 28,
        "intensity": 0.4,
        "count": 14
      },
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  the_low_cellars: {
    "image": "assets/images/scenes/the_low_cellars.jpg",
    "subtitle": "what is under the tarpaulins",
    "exits": {
      "the_tide_gate": {
        "x": 14,
        "y": 90,
        "icon": "←"
      },
      "the_deep_store": {
        "x": 50,
        "y": 90,
        "icon": "⌂"
      },
      "underquay_stair": {
        "x": 86,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "A covered stack",
        "x": 54,
        "y": 50,
        "act": "search"
      },
      {
        "label": "The brick plinths",
        "x": 80,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "water",
        "x": 0,
        "y": 72,
        "w": 100,
        "h": 28,
        "intensity": 0.4,
        "count": 14
      },
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  the_tide_gate: {
    "image": "assets/images/scenes/the_tide_gate.jpg",
    "subtitle": "cut exactly wide enough",
    "exits": {
      "the_low_cellars": {
        "x": 25,
        "y": 90,
        "icon": "⌂"
      },
      "the_drowned_room": {
        "x": 75,
        "y": 90,
        "icon": "⌂"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The cut bars",
        "x": 68,
        "y": 72,
        "act": "search"
      },
      {
        "label": "The crane's legs",
        "x": 72,
        "y": 40,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "water",
        "x": 0,
        "y": 72,
        "w": 100,
        "h": 28,
        "intensity": 0.4,
        "count": 14
      },
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  the_drowned_room: {
    "image": "assets/images/scenes/the_drowned_room.jpg",
    "subtitle": "a name used once a generation",
    "exits": {
      "the_tide_gate": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "Shapes on the bottom",
        "x": 50,
        "y": 78,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "water",
        "x": 0,
        "y": 72,
        "w": 100,
        "h": 28,
        "intensity": 0.4,
        "count": 14
      },
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  the_deep_store: {
    "image": "assets/images/scenes/the_deep_store.jpg",
    "subtitle": "League grain, in League sacking",
    "exits": {
      "the_low_cellars": {
        "x": 50,
        "y": 90,
        "icon": "⌂"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The sacks",
        "x": 60,
        "y": 48,
        "act": "search"
      },
      {
        "label": "The air shaft",
        "x": 50,
        "y": 8,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  wardens_road: {
    "image": "assets/images/scenes/wardens_road.jpg",
    "subtitle": "a street nobody decided on",
    "exits": {
      "the_hallowgate": {
        "x": 14,
        "y": 77,
        "icon": "↑"
      },
      "the_green_bough": {
        "x": 50,
        "y": 77,
        "icon": "⌂"
      },
      "the_lazar_house": {
        "x": 86,
        "y": 77,
        "icon": "↓"
      },
      "the_grey_hall": {
        "x": 14,
        "y": 90,
        "icon": "←"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The graded ditch",
        "x": 58,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      },
      {
        "type": "smoke",
        "x": 10,
        "y": 0,
        "w": 12,
        "h": 20,
        "intensity": 0.35,
        "wind": 0.5
      }
    ]
  },
  the_grey_hall: {
    "image": "assets/images/scenes/the_grey_hall.jpg",
    "subtitle": "an embassy nobody received",
    "exits": {
      "chapel_of_the_grey_road": {
        "x": 25,
        "y": 90,
        "icon": "↩"
      },
      "wardens_road": {
        "x": 75,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The map of the Wyrdpines",
        "x": 68,
        "y": 40,
        "act": "search"
      },
      {
        "label": "The record books",
        "x": 30,
        "y": 52,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  chapel_of_the_grey_road: {
    "image": "assets/images/scenes/chapel_of_the_grey_road.jpg",
    "subtitle": "asked for a road home",
    "exits": {
      "the_grey_hall": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The painted door",
        "x": 31,
        "y": 40,
        "act": "search"
      },
      {
        "label": "The candles",
        "x": 63,
        "y": 74,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  the_green_bough: {
    "image": "assets/images/scenes/the_green_bough.jpg",
    "subtitle": "everyone in it left somewhere",
    "exits": {
      "wardens_road": {
        "x": 50,
        "y": 90,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The green bough",
        "x": 58,
        "y": 30,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  the_lazar_house: {
    "image": "assets/images/scenes/the_lazar_house.jpg",
    "subtitle": "not sure what with",
    "exits": {
      "wardens_road": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The long room",
        "x": 50,
        "y": 50,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  brindles_chandlery: {
    image: 'assets/images/scenes/brindles_chandlery.jpg',
    subtitle: 'anything a boat runs out of',
    exits: {
      brackwater_wharf: {x: 30, y: 62, icon: "↩"}
    },
    npcs: {
      hester_brindle: {x: 66, y: 50}
    },
    objects: [
      {label: "The ledger", x: 66, y: 66, act: "search"},
      {label: "Second-hand sea chests", x: 86, y: 30, act: "search"}
    ],
    fx: [
      {type: "dust", x: 20, y: 25, w: 45, h: 45, intensity: 0.3, count: 10}
    ]
  },
  priory_gate: {
    "image": "assets/images/scenes/priory_gate.jpg",
    "exits": {
      "priory_track": {
        "x": 12,
        "y": 82,
        "icon": "↓"
      },
      "priory_cloister": {
        "x": 64,
        "y": 52,
        "icon": "⌂"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The bench",
        "x": 61,
        "y": 64,
        "act": "search"
      },
      {
        "label": "The bell-rope",
        "x": 64,
        "y": 32,
        "act": "search"
      },
      {
        "label": "The alms slot",
        "x": 86,
        "y": 42,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 2,
        "y": 4,
        "w": 30,
        "h": 18,
        "intensity": 0.5
      }
    ]
  },
  priory_cloister: {
    "image": "assets/images/scenes/priory_cloister.jpg",
    "exits": {
      "priory_chapel": {
        "x": 57,
        "y": 52,
        "icon": "↑"
      },
      "priory_scriptorium": {
        "x": 8,
        "y": 56,
        "icon": "←"
      },
      "priory_gate": {
        "x": 95,
        "y": 56,
        "icon": "→"
      },
      "priory_infirmary": {
        "x": 50,
        "y": 92,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The glazed arcade",
        "x": 20,
        "y": 44,
        "act": "search"
      },
      {
        "label": "The herb beds",
        "x": 84,
        "y": 80,
        "act": "search"
      },
      {
        "label": "The bell",
        "x": 59,
        "y": 22,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 30,
        "y": 3,
        "w": 45,
        "h": 16,
        "intensity": 0.4
      }
    ]
  },
  priory_chapel: {
    "image": "assets/images/scenes/priory_chapel.jpg",
    "exits": {
      "priory_cloister": {
        "x": 50,
        "y": 92,
        "icon": "↓"
      },
      "priory_reliquary": {
        "x": 37,
        "y": 46,
        "icon": "⌂"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The sun-disc",
        "x": 52,
        "y": 28,
        "act": "search"
      },
      {
        "label": "The brackets",
        "x": 15,
        "y": 26,
        "act": "search"
      },
      {
        "label": "The worn floor",
        "x": 50,
        "y": 76,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 70,
        "y": 10,
        "w": 25,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  priory_reliquary: {
    "image": "assets/images/scenes/priory_reliquary.jpg",
    "exits": {
      "priory_chapel": {
        "x": 30,
        "y": 70,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The cards",
        "x": 68,
        "y": 50,
        "act": "search"
      },
      {
        "label": "The grille",
        "x": 67,
        "y": 16,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 55,
        "y": 5,
        "w": 25,
        "h": 45,
        "intensity": 0.3,
        "count": 8
      }
    ]
  },
  priory_scriptorium: {
    "image": "assets/images/scenes/priory_scriptorium.jpg",
    "exits": {
      "priory_cloister": {
        "x": 94,
        "y": 55,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The clear desk",
        "x": 38,
        "y": 54,
        "act": "search"
      },
      {
        "label": "The cartulary presses",
        "x": 43,
        "y": 34,
        "act": "search"
      },
      {
        "label": "The window",
        "x": 79,
        "y": 30,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 60,
        "y": 10,
        "w": 30,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  priory_infirmary: {
    "image": "assets/images/scenes/priory_infirmary.jpg",
    "exits": {
      "priory_cloister": {
        "x": 50,
        "y": 92,
        "icon": "↓"
      },
      "teodors_cell": {
        "x": 63,
        "y": 40,
        "icon": "⌂"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The made beds",
        "x": 84,
        "y": 68,
        "act": "search"
      },
      {
        "label": "The yard door",
        "x": 8,
        "y": 50,
        "act": "search"
      },
      {
        "label": "The hearth",
        "x": 50,
        "y": 44,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "glow",
        "x": 44,
        "y": 38,
        "w": 14,
        "h": 16,
        "intensity": 0.4
      },
      {
        "type": "glow",
        "x": 10,
        "y": 82,
        "w": 18,
        "h": 16,
        "intensity": 0.4
      }
    ]
  },
  teodors_cell: {
    "image": "assets/images/scenes/teodors_cell.jpg",
    "exits": {
      "priory_infirmary": {
        "x": 9,
        "y": 55,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The writing-board",
        "x": 33,
        "y": 40,
        "act": "search"
      },
      {
        "label": "The cup on the shelf",
        "x": 45,
        "y": 28,
        "act": "search"
      },
      {
        "label": "The shutter",
        "x": 80,
        "y": 35,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 55,
        "y": 15,
        "w": 35,
        "h": 55,
        "intensity": 0.3,
        "count": 10
      }
    ]
  },
  fen_causeway: {
    "image": "assets/images/scenes/fen_causeway.jpg",
    "exits": {
      "fen_road_head": {
        "x": 50,
        "y": 90,
        "icon": "↩"
      },
      "drowned_village": {
        "x": 80,
        "y": 30,
        "icon": "↑"
      },
      "stilt_camp": {
        "x": 14,
        "y": 36,
        "icon": "←"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The withies",
        "x": 69,
        "y": 62,
        "act": "search"
      },
      {
        "label": "The drowned stretch",
        "x": 27,
        "y": 30,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 30,
        "y": 3,
        "w": 45,
        "h": 16,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 15,
        "w": 100,
        "h": 20,
        "intensity": 0.25
      }
    ]
  },
  drowned_village: {
    "image": "assets/images/scenes/drowned_village.jpg",
    "exits": {
      "fen_causeway": {
        "x": 86,
        "y": 90,
        "icon": "↩"
      },
      "drowned_chapel": {
        "x": 30,
        "y": 25,
        "icon": "←"
      },
      "black_pools": {
        "x": 10,
        "y": 86,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The street",
        "x": 55,
        "y": 45,
        "act": "search"
      },
      {
        "label": "An open doorway",
        "x": 32,
        "y": 72,
        "act": "search"
      },
      {
        "label": "The rafters",
        "x": 80,
        "y": 48,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 16,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 18,
        "w": 100,
        "h": 18,
        "intensity": 0.25
      }
    ]
  },
  drowned_chapel: {
    "image": "assets/images/scenes/drowned_chapel.jpg",
    "exits": {
      "drowned_village": {
        "x": 91,
        "y": 42,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The chest on the step",
        "x": 47,
        "y": 31,
        "act": "search"
      },
      {
        "label": "The black water",
        "x": 40,
        "y": 72,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 60,
        "intensity": 0.3
      },
      {
        "type": "fog",
        "x": 0,
        "y": 55,
        "w": 100,
        "h": 40,
        "intensity": 0.2
      }
    ]
  },
  black_pools: {
    "image": "assets/images/scenes/black_pools.jpg",
    "exits": {
      "drowned_village": {
        "x": 53,
        "y": 16,
        "icon": "↑"
      },
      "stilt_camp": {
        "x": 89,
        "y": 25,
        "icon": "→"
      },
      "sluice_gate": {
        "x": 14,
        "y": 30,
        "icon": "←"
      },
      "root_beds": {
        "x": 4,
        "y": 62,
        "icon": "←"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "Floating peat",
        "x": 45,
        "y": 78,
        "act": "search"
      },
      {
        "label": "A sedge island",
        "x": 52,
        "y": 40,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 40,
        "y": 3,
        "w": 45,
        "h": 16,
        "intensity": 0.3
      },
      {
        "type": "fog",
        "x": 0,
        "y": 16,
        "w": 100,
        "h": 20,
        "intensity": 0.3
      }
    ]
  },
  stilt_camp: {
    "image": "assets/images/scenes/stilt_camp.jpg",
    "exits": {
      "fen_causeway": {
        "x": 52,
        "y": 24,
        "icon": "↑"
      },
      "black_pools": {
        "x": 7,
        "y": 56,
        "icon": "←"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The faggot stack",
        "x": 65,
        "y": 26,
        "act": "search"
      },
      {
        "label": "The drying nets",
        "x": 21,
        "y": 30,
        "act": "search"
      },
      {
        "label": "The punts",
        "x": 36,
        "y": 82,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 30,
        "y": 3,
        "w": 45,
        "h": 16,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 16,
        "w": 100,
        "h": 16,
        "intensity": 0.25
      }
    ]
  },
  root_beds: {
    "image": "assets/images/scenes/root_beds.jpg",
    "exits": {
      "black_pools": {
        "x": 95,
        "y": 50,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The uncut bed",
        "x": 72,
        "y": 45,
        "act": "search"
      },
      {
        "label": "The cut beds",
        "x": 30,
        "y": 62,
        "act": "search"
      },
      {
        "label": "The plank stage",
        "x": 58,
        "y": 86,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "birds",
        "x": 30,
        "y": 3,
        "w": 45,
        "h": 16,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 18,
        "w": 100,
        "h": 16,
        "intensity": 0.25
      }
    ]
  },
  sluice_gate: {
    "image": "assets/images/scenes/sluice_gate.jpg",
    "exits": {
      "black_pools": {
        "x": 86,
        "y": 44,
        "icon": "→"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The screw-jack",
        "x": 39,
        "y": 15,
        "act": "search"
      },
      {
        "label": "The gate",
        "x": 40,
        "y": 55,
        "act": "search"
      },
      {
        "label": "The hut on the pier",
        "x": 11,
        "y": 25,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "smoke",
        "x": 4,
        "y": 0,
        "w": 6,
        "h": 10,
        "intensity": 0.35,
        "wind": 0.5
      },
      {
        "type": "birds",
        "x": 50,
        "y": 3,
        "w": 40,
        "h": 16,
        "intensity": 0.4
      }
    ]
  },
  sump_drain: {
    "image": "assets/images/scenes/sump_drain.jpg",
    "exits": {
      "flagon_cellar": {
        "x": 10,
        "y": 50,
        "icon": "↩"
      },
      "low_conduit": {
        "x": 78,
        "y": 32,
        "icon": "↑"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The scuffed ledge",
        "x": 40,
        "y": 78,
        "act": "search"
      },
      {
        "label": "The channel",
        "x": 66,
        "y": 70,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  low_conduit: {
    "image": "assets/images/scenes/low_conduit.jpg",
    "exits": {
      "sump_drain": {
        "x": 50,
        "y": 92,
        "icon": "↩"
      },
      "weir_chamber": {
        "x": 48,
        "y": 50,
        "icon": "↑"
      },
      "drowned_counting_room": {
        "x": 80,
        "y": 62,
        "icon": "→"
      },
      "low_court": {
        "x": 12,
        "y": 80,
        "icon": "←"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The stopped drains",
        "x": 10,
        "y": 28,
        "act": "search"
      },
      {
        "label": "The water down the middle",
        "x": 46,
        "y": 80,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "water",
        "x": 28,
        "y": 60,
        "w": 40,
        "h": 40,
        "intensity": 0.4,
        "count": 12
      },
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.2
      }
    ]
  },
  weir_chamber: {
    "image": "assets/images/scenes/weir_chamber.jpg",
    "exits": {
      "low_conduit": {
        "x": 50,
        "y": 92,
        "icon": "↩"
      },
      "flood_gate": {
        "x": 80,
        "y": 40,
        "icon": "→"
      },
      "bricked_arch": {
        "x": 14,
        "y": 50,
        "icon": "←"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The weir",
        "x": 50,
        "y": 62,
        "act": "search"
      },
      {
        "label": "The rail",
        "x": 20,
        "y": 40,
        "act": "search"
      },
      {
        "label": "The pool",
        "x": 65,
        "y": 82,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "water",
        "x": 35,
        "y": 70,
        "w": 45,
        "h": 25,
        "intensity": 0.5,
        "count": 16
      },
      {
        "type": "drip",
        "x": 30,
        "y": 0,
        "w": 50,
        "h": 60,
        "intensity": 0.4
      },
      {
        "type": "fog",
        "x": 0,
        "y": 60,
        "w": 100,
        "h": 40,
        "intensity": 0.25
      }
    ]
  },
  flood_gate: {
    "image": "assets/images/scenes/flood_gate.jpg",
    "exits": {
      "weir_chamber": {
        "x": 12,
        "y": 88,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The wheel",
        "x": 61,
        "y": 63,
        "act": "search"
      },
      {
        "label": "The greased screw",
        "x": 53,
        "y": 30,
        "act": "search"
      },
      {
        "label": "The new pintles",
        "x": 36,
        "y": 40,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.3
      },
      {
        "type": "fog",
        "x": 0,
        "y": 65,
        "w": 100,
        "h": 35,
        "intensity": 0.2
      }
    ]
  },
  low_court: {
    "image": "assets/images/scenes/low_court.jpg",
    "exits": {
      "low_conduit": {
        "x": 55,
        "y": 48,
        "icon": "↑"
      },
      "grain_stair": {
        "x": 95,
        "y": 62,
        "icon": "→"
      },
      "bricked_arch": {
        "x": 67,
        "y": 38,
        "icon": "↑"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The bench",
        "x": 50,
        "y": 66,
        "act": "search"
      },
      {
        "label": "The curtained bays",
        "x": 12,
        "y": 50,
        "act": "search"
      },
      {
        "label": "The lamps",
        "x": 46,
        "y": 20,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 25,
        "y": 20,
        "w": 50,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  },
  drowned_counting_room: {
    "image": "assets/images/scenes/drowned_counting_room.jpg",
    "exits": {
      "low_conduit": {
        "x": 6,
        "y": 82,
        "icon": "↩"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The strongroom",
        "x": 73,
        "y": 48,
        "act": "search"
      },
      {
        "label": "The cornice",
        "x": 30,
        "y": 14,
        "act": "search"
      },
      {
        "label": "The box-plates",
        "x": 71,
        "y": 38,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "water",
        "x": 0,
        "y": 68,
        "w": 100,
        "h": 32,
        "intensity": 0.35,
        "count": 12
      },
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 60,
        "intensity": 0.3
      }
    ]
  },
  bricked_arch: {
    "image": "assets/images/scenes/bricked_arch.jpg",
    "exits": {
      "weir_chamber": {
        "x": 50,
        "y": 92,
        "icon": "↩"
      },
      "low_court": {
        "x": 18,
        "y": 46,
        "icon": "↓"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The grey fill",
        "x": 69,
        "y": 50,
        "act": "search"
      },
      {
        "label": "The old arch",
        "x": 58,
        "y": 22,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "drip",
        "x": 20,
        "y": 0,
        "w": 60,
        "h": 70,
        "intensity": 0.3
      },
      {
        "type": "fog",
        "x": 0,
        "y": 65,
        "w": 100,
        "h": 35,
        "intensity": 0.2
      }
    ]
  },
  grain_stair: {
    "image": "assets/images/scenes/grain_stair.jpg",
    "exits": {
      "low_court": {
        "x": 8,
        "y": 52,
        "icon": "←"
      },
      "granary_row": {
        "x": 72,
        "y": 22,
        "icon": "↑"
      }
    },
    "npcs": {},
    "objects": [
      {
        "label": "The broken barrow",
        "x": 78,
        "y": 80,
        "act": "search"
      },
      {
        "label": "The swept landing",
        "x": 40,
        "y": 84,
        "act": "search"
      }
    ],
    "fx": [
      {
        "type": "dust",
        "x": 40,
        "y": 10,
        "w": 40,
        "h": 50,
        "intensity": 0.25,
        "count": 8
      }
    ]
  }
};
