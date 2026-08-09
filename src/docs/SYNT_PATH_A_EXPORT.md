# Synty → Rivermark Path A Export Plan

**Date:** 2026-07-25  
**Source tree:** `/home/www/rpg/assets/Synty` (~6.3 GB Unity dump)  
**Target:** Keep current PHP/JS isometric engine; replace art with **rendered sprites** from your Synty packs.

Path A = **Unity (or Blender) as a render farm**, not live 3D in the browser.

---

## 1. Pack → game role map

| Game need | Synty pack | Why |
|-----------|------------|-----|
| **Player / party heroes** | `PolygonFantasyHeroCharacters` | Modular heroes + presets + weapons |
| **Town NPCs** (innkeeper, merchant, peasants, mage, captain-ish) | `PolygonFantasyCharacters` | Ready `SM_Chr_*` prefabs (wizard, peasant, bard, king, witch, druid…) |
| **Dungeon enemies** | `PolygonFantasyRivals` + `PolygonDarkFantasy` | Orcs, trolls, demons, skeletons, golems, etc. |
| **Town / inn / streets** | `PolygonFantasyVillage` | Buildings, furniture, signs, paths, interiors |
| **Kingdom / larger town props** | `PolygonFantasyKingdom` | Extra architecture if Village isn’t enough |
| **Wilderness / grass / trees** | `PolygonNatureBiomes` + `PNB_Core` | Meadows, ground, flora |
| **Generic fill** | `PolygonGeneric` | Shared modular bits |
| **Walk / idle animation (for sprite frames)** | `AnimationBaseLocomotion`, `AnimationIdles` | Drive walk cycles when rendering |
| **Combat poses (optional later)** | `AnimationSwordCombat`, `AnimationBowCombat` | Attack frames if you expand combat juice |
| **UI chrome** | `InterfaceFantasyMenus`, `InterfaceCore` | **Already 2D PNGs** — can use with little conversion |
| **Custom modular heroes (later)** | `SidekickCharacters` | Powerful but heaviest; skip for MVP |

### Do **not** serve from the web

Keep `/assets/Synty` **private** (or outside the web root). Only ship exported files under e.g.:

```
assets/images/tiles/
assets/images/npcs/
assets/images/monsters/
assets/images/ui/
```

---

## 2. Game role → exact Synty sources (MVP)

### 2.1 Party / hero

| Output file (engine) | Synty source (pick one preset to start) |
|----------------------|----------------------------------------|
| `npcs/hero.png` | `PolygonFantasyHeroCharacters/Prefabs/Characters_Presets/Chr_FantasyHero_Preset_28` (or any preset you like) |
| `npcs/hero_walk1.png` … `walk3.png` | Same prefab + `AnimationBaseLocomotion` walk clips (`A_Walk_F_*`) |

**MVP tip:** One fixed hero preset is enough. Modular Sidekick is overkill until the look is locked.

### 2.2 NPCs (current game IDs)

| Game NPC | Engine path | Synty prefab (from `PolygonFantasyCharacters`) |
|----------|-------------|--------------------------------------------------|
| Mara Hearthstone (Innkeeper) | `npcs/innkeeper.png` | `SM_Chr_Female_Peasant_01` or `_02` |
| Tobin Brassscale (Merchant) | `npcs/merchant.png` | `SM_Chr_Male_Peasant_01` or bard `SM_Chr_Male_Baird_01` |
| Captain Elowen Reed | `npcs/captain.png` | Hero preset in armor **or** kingdom/village guard if you have one; interim: `SM_Chr_Male_King_01` / female warrior hero preset |
| Brother Aldric (Cleric) | `npcs/cleric.png` | `SM_Chr_Male_Wizard_01` or `SM_Chr_Male_Sorcerer_01` (robe look) |
| Job Board | `npcs/jobboard.png` | Village `SM_Prop_Sign_*` or notice-board style prop (render as prop, not character) |

### 2.3 Monsters (current seed)

| Game monster | Engine path | Synty source |
|--------------|-------------|--------------|
| Goblin | `monsters/goblin.png` | Rivals small humanoid (e.g. dwarf/mutant stand-in) **or** DarkFantasy small creature; if no 1:1 goblin, use `SM_Chr_BR_Dwarf_01` / small rival as placeholder |
| Orc | `monsters/orc.png` | **`SM_Chr_BR_BigOrk_01`** (Rivals) |
| Skeleton | `monsters/skeleton.png` | **`SM_Chr_Skeleton_01`** / `SM_Chr_Skeleton_HeavyArmor_01` (DarkFantasy) |
| Zombie | `monsters/zombie.png` | `SM_Chr_Skeleton_Flesh_01` or similar undead |
| Wolf | `monsters/wolf.png` | Nature animal if present; else skip / keep current until found |
| Giant Rat | `monsters/giant_rat.png` | Small creature stand-in from Rivals/props if no rat |
| Bandit | `monsters/bandit.png` | `SM_Chr_Male_Rouge_01` or `SM_Chr_BR_Slayer_01` |
| Kobold | `monsters/kobold.png` | Small humanoid rival stand-in |
| Ogre | `monsters/ogre.png` | **`SM_Chr_BR_Troll_01`** or `SM_Chr_BR_BarbarianGiant_01` |
| Stirge | `monsters/stirge.png` | Winged attach / small flyer; optional for MVP |

Exact 1:1 D&D names rarely exist in Synty — **map by silhouette**, not name.

### 2.4 Tiles (terrain → seamless ground OR orthographic plane render)

| Terrain key | Engine path | Synty source pack |
|-------------|-------------|-------------------|
| `wood_floor` / inn floor | `tiles/floor_wood.png` | Village floor / interior wood props (render top-down plane) |
| `floor` dungeon | `tiles/floor_dungeon.png` | DarkFantasy `SM_Bld_Base_Floor_01` |
| `wall` / stone wall | `tiles/wall_stone.png` | DarkFantasy `SM_Bld_Base_Wall_01` (or keep solid CSS walls) |
| `wall` inn | `tiles/wall_inn.png` | Village wall / plaster building wall |
| `grass` | `tiles/grass.png` | Nature biomes ground / Village grass |
| `road` | `tiles/road.png` | Village path / stone slab / stepping stone |
| `water` | `tiles/water.png` | Village river preset / simple plane + water material |
| `carpet` | `tiles/carpet.png` | Village rug prop (top-down) |
| `door` / `gate` / `stairs` / `inn` / `hearth` / `table` / `counter` | matching `tiles/*.png` | Village doors, gates, stairs, oven/hearth, tables |

**MVP shortcut for walls:** keep the **solid CSS extruded walls** and only render **floor + characters + key props**. That cuts export work a lot.

### 2.5 UI (almost free)

| Use | Source |
|-----|--------|
| Cursor | `InterfaceFantasyMenus/Sprites/Cursors/*.png` |
| Panel/glow FX | `InterfaceFantasyMenus/Sprites/FX/*.png` |
| Buttons/frames | Other sprites under `InterfaceFantasyMenus` |

These are already 2D — copy/rename into `assets/images/ui/` after checking license/usage (your ownership assumed).

---

## 3. Minimal Path A export list (phase 1 — shippable)

**Goal:** One town + one dungeon + hero walk + 4 NPCs + 6 monsters.  
**Count:** ~40–60 PNG files (not thousands of FBX).

### Characters (idle + optional walk)

| # | Output | Frames |
|---|--------|--------|
| 1 | `hero.png` | idle |
| 2–4 | `hero_walk1..3.png` | walk cycle |
| 5 | `innkeeper.png` | idle |
| 6 | `merchant.png` | idle |
| 7 | `captain.png` | idle |
| 8 | `cleric.png` | idle |
| 9 | `jobboard.png` | static prop |

### Monsters (idle only for MVP)

| # | Output |
|---|--------|
| 10–15 | `goblin`, `orc`, `skeleton`, `zombie`, `bandit`, `ogre` (minimum set) |

### Tiles / props

| # | Output |
|---|--------|
| 16–22 | `floor_wood`, `floor_dungeon`, `grass`, `road`, `floor_stone`, `carpet`, `water` |
| 23–28 | `door_wood`, `stairs`, `gate`, `inn`, `hearth`, `table` (or `counter`) |

### Optional phase 1b

- 2 more monsters (wolf, kobold)  
- UI cursor + 1 panel frame  

**Do not export in phase 1:** full Sidekick modular library, all kingdom kits, full animation libraries (only sample clips to drive renders).

---

## 4. Unity render recipe (copy this into a small Unity project)

### Camera (match current iso game)

- **Projection:** Orthographic  
- **Angle:** ~35–45° pitch, 45° yaw (classic “iso” look for low-poly)  
- **OR** pure top-down 90° for ground tiles only  
- **Background:** solid chroma key (e.g. pure magenta `#FF00FF`) or transparent via render texture  

Recommended split:

| Asset type | Camera |
|------------|--------|
| Ground tiles | **Top-down ortho** (easier seamless / square crop) |
| Characters / monsters / standing props | **Iso ortho** (matches current billboard) |

### Character render settings

- Resolution: **256×256** or **512×512** PNG, then scale to 160–256 for web  
- Character centered, **full body**, feet near bottom third  
- Single key light + soft fill (Synty demo lighting is fine)  
- Transparent background preferred  
- For walk: 4 frames of `A_Walk_F` (or 8 if you want smoother cycle)  

### Ground tile settings

- Flat plane / floor mesh filling the frame  
- **No** perspective stretch  
- Export as **seamless as possible** (or accept non-seamless and keep current CSS diamonds)  
- 256×256 or 128×128  

### File naming

Use the engine names above exactly, then:

```bash
# example
cp export/hero.png /home/www/rpg/assets/images/npcs/hero.png
# bump cache query in game.js bodySpriteUrl if needed (v=body3)
```

---

## 5. Engine integration checklist (after export)

1. Drop PNGs into `assets/images/npcs|monsters|tiles`.  
2. Bump `bodySpriteUrl` / `TILE_CACHE_VER` in `assets/js/game.js`.  
3. Hard-refresh browser.  
4. Tune CSS if Synty scale differs:
   - `.iso-sprite.body-sprite` width/height  
5. UI: point cursors/CSS at copied Interface sprites if desired.  
6. **Never** symlink whole `assets/Synty` into production web root.

No PHP/schema changes required for Path A art swap.

---

## 6. Suggested priority order (do this sequence)

| Step | What | Effort |
|------|------|--------|
| **0** | Unity project with only: HeroCharacters + FantasyCharacters + Village + DarkFantasy + BaseLocomotion | 1–2 hours |
| **1** | Render hero idle + 3 walk frames → drop in | half day |
| **2** | Render 4 NPCs + job board prop | half day |
| **3** | Render 6 monsters | half day |
| **4** | Render 6–8 ground tiles | 1 day |
| **5** | Optional: door/hearth/table props | half day |
| **6** | Optional: UI cursor/panel | 1–2 hours |

**First visual win:** Step 1–2 only (characters). You’ll know immediately if the Synty look fits the iso board.

---

## 7. What we deliberately skip (for now)

| Skip | Reason |
|------|--------|
| Live FBX/GLB in browser | Path B rewrite |
| Sidekick modular runtime | Huge; use Hero **presets** instead |
| Full animation sets in browser | Only sample frames when rendering |
| Perfect SRD silhouette matches | Synty isn’t D&D; map by role |
| Seamless autotiles from modular walls | Complex; solid walls or simple floors first |

---

## 8. One-page “export sheet” (print this)

**Unity scene: Rivermark_Export**

**Characters (iso camera, magenta BG):**  
- Hero preset → `hero.png` + walk ×3  
- Female peasant → `innkeeper.png`  
- Male peasant / bard → `merchant.png`  
- Armored hero preset / captain stand-in → `captain.png`  
- Wizard → `cleric.png`  
- Sign prop → `jobboard.png`  

**Enemies (iso camera):**  
- BigOrk → `orc.png`  
- Skeleton → `skeleton.png`  
- Skeleton flesh → `zombie.png`  
- Slayer/Rogue → `bandit.png`  
- Troll/Giant → `ogre.png`  
- Small humanoid → `goblin.png`  

**Tiles (top-down camera):**  
- Wood floor, dungeon floor, grass, path, stone, water, carpet  

**Output folder:**  
`/home/www/rpg/assets/images/{npcs,monsters,tiles}/`

---

## 9. Next action after this doc

1. In Unity, create a project and import **only** the packs in §6 step 0.  
2. Export the **hero walk set** first and drop into `assets/images/npcs/`.  
3. Tell me when those files are in place — I can wire cache versions, scale, and any rename mapping in the game.

If you want, the next implementation step on this server (no Unity required from me) is: a small **`docs/synty-filename-map.csv`** plus a script that copies exports from a staging folder into the right engine paths.
