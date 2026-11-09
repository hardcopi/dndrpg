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

## 3. Darkness (after that)

Uses the SRD's light levels (bright, dim, darkness) and the unseen-attacker
rules:

- an attack against a target the attacker cannot see has disadvantage;
- an attack by an attacker the target cannot see has advantage.

Cavern and tunnel boards are dark apart from their braziers and coals.
Darkvision then becomes a trait the game actually applies; until then it is
the standing example in `CLAUDE.md` of a trait the engine does not enforce.
