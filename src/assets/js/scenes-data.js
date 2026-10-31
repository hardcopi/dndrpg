/* Scene records use scene-box percentages; drop an image and add an entry to add a scene. */
window.RIVERMARK_SCENES = {
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
  }
};
