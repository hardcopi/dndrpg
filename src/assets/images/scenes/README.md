# Painted scenes

A location with an entry in `assets/js/scenes-data.js` (`window.RIVERMARK_SCENES`)
shows its painting in the middle of the play screen instead of the region map.
Everything else falls back to the map. The **Map** chip on the scene (or **M**)
still opens the region chart.

## Adding a scene

1. Drop a **16:9** painting here as `<location_key>.jpg` (1600×900 or so, JPEG ~q85).
   It is shown whole, never cropped, so every position below lines up.
2. Add one record to `assets/js/scenes-data.js`, keyed by the location's `location_key`
   (`src/content/locations/*.json`):

```js
the_anvil: {
  image: 'assets/images/scenes/the_anvil.jpg',
  subtitle: 'smoke and hammer-song',            // optional, italic beside the name
  exits: {                                       // keyed by the destination location_key
    market_square: { x: 12, y: 70, icon: '↩' }   // x/y = % of the scene box; the pin's tip sits there
  },                                             // label text comes from the live exit label
  npcs: {                                        // keyed by npc_key (src/content/npcs)
    smith_harlan: { x: 55, y: 58 }
  },
  objects: [                                     // little dotted labels; click runs Search
    { label: 'the quench barrel', x: 70, y: 75, act: 'search' }
  ],
  fx: [                                          // RivermarkFX effects (assets/js/ui-scenefx.js header)
    { type: 'fire', x: 80, y: 45, w: 10, h: 18, intensity: 1 },
    { type: 'smoke', x: 82, y: -5, w: 12, h: 18, wind: 0.5 }
  ]
}
```

## How hotspots behave

- Exits come from the live location payload, so a locked exit is drawn greyed and
  disabled, and an exit with no configured spot is still in the "Ways out" line under the prose.
- NPCs are drawn only when they are actually at the location right now. Configured
  NPCs use their spot; any other **non-ambient** NPC gets an automatic spot along
  the bottom (max 5, then "+N more"). Ambient townsfolk without a spot stay in the
  People Here rail only.
- Labels with x > 80% hang to the left of their pin and x < 20% to the right, so they stay inside the picture.
- Suggested effect types for all 148 locations: `location-effects.json` in the art
  hand-off (smoke, fire, hearth, torch, candle, glow, birds, water, fog, dust, drip, rain, fireflies, sway).
