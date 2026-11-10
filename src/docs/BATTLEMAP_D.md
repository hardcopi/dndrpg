# Battle map D: terrain that changes mid-fight

The fifth battle-map upgrade Rich picked on Oct 31 2026, after decals and themed
obstacles (#32), animation (#34), the tilted diorama (#36–#38) and terrain with
rules (`BattleGrid::TERRAIN`). It ships in three parts, in this order:
**braziers**, then **high ground and ledges**, then **darkness**.

The working design note (`/workspace/cc/battlemap/DE-design.md`) was lost in a
disk cleanup and is in no commit. These rules were rebuilt from the SRD and
from what the engine already enforces. Where the SRD has no rule, the gap is
named here instead of being filled with something invented.

## 1. Braziers (shipped)

| Terrain | Char | Move | Sight | Cover |
|---|---|---|---|---|
| lit brazier | `b` | blocked | clear | half (+2) |
| overturned brazier | `u` | blocked | clear | half (+2) |
| burning coals | `f` | 10 ft | clear | none |

- **Where they appear.** The layout letter `Z` appears in one new layout each
  for the arena, interior, cavern, camp and tunnel palettes. Street and forest
  boards never have braziers.
- **Tip brazier is an action** for anybody within 5 ft of a lit brazier. The
  bar offers it only when one is in reach (`ui.tip`). The brazier falls away
  from you, and its coals land on the three cells beyond it, fanned the way it
  fell (`CombatEngine::spillCells`). Walls, pits, furniture and water catch no
  coals.
- **Caught in the spill:** a Dexterity save at DC 12. A failure takes 2d6 fire
  damage and a success takes half. Resistances apply (`Rules::damageAfterDefenses`).
- **Burning ground:** walking into a coal cell, or starting a turn on one,
  deals 1d6 fire damage. That happens at most once per turn, and being caught
  by the spill counts as that turn's burn.
- **Two rounds:** the coals burn for two rounds and are checked at the top of
  each round. After that they turn to ash, which is ordinary rough ground (`,`).
- **The brazier stays put.** Once tipped it is still half cover but is no
  longer lit, so its light goes out with it. That matters for part 3.
- **Paint:**
  - `props/brazier.png` and `props/brazier_tipped.png` are the uprights.
  - `props/coals_fire.png` is a standing flame on each burning cell. It is
    paint only, removed when the coals burn out.
  - `decals/coals.png` is the burning floor and `decals/ash.png` what is left.
  - Lit braziers and coals glow (`firePlan()` in `ui-battlemap.js`) and light
    the figures near them.
- **Not done yet:** monsters do not tip braziers, and they do not yet steer
  clear of coals beyond the doubled movement cost.
- Tests: `tools/test_combat.php`, section "Braziers (battle map D)".

## 2. High ground and ledges (shipped)

| Terrain | Char | Move | Sight | Cover |
|---|---|---|---|---|
| high ground | `^` | 5 ft on top, **10 ft to climb up** | clear | none |

- **Where it appears.** Layout letter `H`, in a new layout each for the arena
  (a dais with braziers at its foot), cavern (a rock shelf), street (raised
  walks), forest (a knoll) and camp (a bank).
- **Climbing.** Stepping from lower ground onto `^` costs 5 ft more
  (`BattleGrid::stepCost`, `CLIMB_FT`). Walking along the top and coming down
  cost nothing extra. The pathfinder, the move preview and `stepMove` all
  charge the same price.
- **Looking down.** When the attacker stands on `^` and the target does not,
  low cover (`o`, `b`, `u`) between them gives the target no cover
  (`BattleGrid::seenFromAbove`). That covers attacks and the cover bonus to
  Dexterity saves alike. Tall cover, walls and bodies in the way still count.
  The shot's reason says "from high ground".
- **No to-hit bonus.** The SRD has none, so there isn't one.
- **The view.** A raised cell is a paler slab with a lit lip on its upper
  edges and a shadowed drop on its lower ones.
- **Monsters** do not seek out high ground yet.
- Tests: `tools/test_combat.php`, section "High ground (battle map D)".

## 3. Darkness (shipped)

- **Which boards are dark.** Generated cavern and tunnel boards
  (`BattleMapGen::DARK`) carry `grid.dark`. Their only light is the braziers
  and coals, and the painted wall torches are gone from them. Everywhere else,
  including drawn boards and delve rooms, is lit as before. A fight saved before
  this shipped keeps the light it started with.
- **Light.** The SRD has no brazier, so a lit brazier sheds light like a torch:
  bright to 20 ft, dim for 20 ft more. A burning coal cell is a candle (5 and 5).
  An overturned brazier is out. Light travels in straight lines, so walls and
  other opaque terrain throw shadows (`BattleGrid::lightRows`, `BattleGrid::LIGHTS`).
- **Seeing.** Only darkness hides anyone. Dim light is lightly obscured, which
  in the SRD affects Perception and nothing in a fight. You can see a creature
  standing in light from anywhere you have line of sight; one standing in
  darkness only if you have darkvision and it is within range (`BattleGrid::canSee`).
- **Attacks** (SRD unseen attackers and targets): attacking a creature you
  cannot see has disadvantage. Attacking one that cannot see you has advantage.
  When both apply they cancel out. This applies to weapon, spell and
  opportunity attacks alike, worked out in `resolveAttack` and
  `suspendForAttackRoll` (`CombatEngine::sightBetween`). The shot's reason says
  "you cannot see them" or "they cannot see you", and the log says so.
- **Darkvision is now applied.**
  - Dwarves, elves, gnomes, half-elves, half-orcs and tieflings: 60 ft.
  - Dark elves: 120 ft.
  - These are the `darkvision` and `superior_darkvision` race features, so the
    sheet now prints "Applied by the game" against them.
  - Monsters have no senses column, so `CombatEngine::MONSTER_DARK_SIGHT` lists
    them by key. Each value is the SRD figure: the longest of darkvision,
    blindsight and truesight.
  - Rivermark monsters are included only where their own traits say so; the
    bog crawler's Peat-Blind is the only one so far.
- **The view.** The engine sends `state.light`, a row of `B`, `D` or `X` per
  cell, and the board shades dim and dark cells from it. Figures standing in
  the dark are drawn darker, so what looks black is what hides you.
- **Carried light.** Every SRD equipment pack has torches and a tinderbox, and
  this game does not track a pack's mundane contents, so each character is
  assumed to carry a torch (bright 20 ft, dim 20 ft).
  - A lamp or lantern in the inventory (`properties.light`) replaces it: the
    SRD lantern gives 30 ft and 30 ft, and a `[bright, dim]` pair sets its own.
  - On a dark board, anyone without darkvision starts with it lit
    (`CombatEngine::carriedLight`, `light` and `light_lit`).
  - The light moves with its bearer (`BattleGrid::carriedLights`). A lit torch
    lets everybody see you, too.
  - **Douse** is the turn's free object interaction. **Light** takes an action,
    as with the SRD tinderbox. Both appear on the bar only on a dark board
    (`ui.carry`).
  - The **Light** cantrip makes its target shine 20 ft and 20 ft for the rest of
    the fight. Once doused, it needs a new casting.
  - Not modelled: the hand a torch occupies.
- **Spells that need sight.** A spell whose card says "you can see" cannot name
  a creature the caster cannot see (`CombatEngine::needsSight`, `mustSee`), and
  the board's targeting leaves such creatures out. Area spells are placed on
  cells and are not gated.
- **Hooded lantern** (SRD: 5 gp, 2 lb, 30 ft bright and 30 ft dim). Tobin
  stocks three.
- **Monsters keep to the dark.** When a monster that sees in the dark has to
  move to get its shot, it picks a spot its target cannot see if there is one,
  and the cheapest such spot (`bestApproach`, ranked by (not hidden, cost)). Its
  choice of attack weighs the dark like cover (`AI_DARK`): it favours striking
  unseen and avoids swinging at what it cannot see. A monster already in range
  does not move away just to reach the dark.
- **Not done:** monsters carry no lights.
- Tests: `tools/test_combat.php`, section "Darkness (battle map D)".
