#!/usr/bin/env python3
"""
Cut the game's tile and sprite art out of the PVGames "Medieval" RPG Maker packs.

The packs ship as RPG Maker MV sheets on a 48px square grid (A1-A5 autotiles plus
B-E object sheets) and 128px character sheets. This script slices the specific
cells the game uses into individual PNGs under assets/images/, one file per piece,
which is the form every image_url in the database points at — npcs.image_url,
items.icon_url and the sprite sheets the paperdoll is baked from.

Re-runnable: it always overwrites its outputs, so editing a manifest entry and
running again is the way to swap a tile.

    python3 tools/slice_assets.py            # write all art
    python3 tools/slice_assets.py --preview  # also write a contact sheet to check picks

The pack tree is licensed art that may not be redistributed, so it lives outside
the web root and is never served. Override its location with RPG_PACK_DIR.
"""

import argparse
import glob
import json
import os
import sys

try:
    from PIL import Image, ImageDraw
except ImportError:
    sys.exit("This script needs Pillow: pip install Pillow")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# Sibling of the web root, not inside it: nginx serves ROOT, so anything under
# it is public, and these packs may not be redistributed.
PACK = os.environ.get("RPG_PACK_DIR", os.path.join(os.path.dirname(ROOT), "rpg-assets-src"))
TILES_OUT = os.path.join(ROOT, "assets", "images", "tiles")
NPCS_OUT = os.path.join(ROOT, "assets", "images", "npcs")
MONSTERS_OUT = os.path.join(ROOT, "assets", "images", "monsters")
DECOR_OUT = os.path.join(ROOT, "assets", "images", "decor")
BUILDINGS_OUT = os.path.join(ROOT, "assets", "images", "buildings")
ANIM_OUT = os.path.join(ROOT, "assets", "images", "anim")
BATTLERS_OUT = os.path.join(ROOT, "assets", "images", "battlers")
COMBAT_OUT = os.path.join(ROOT, "assets", "images", "combat")

TILE = 48       # RPG Maker MV grid
CHAR = 128      # PVGames character frame

DUNGEON = "Medieval_Dungeons_Tiles"
INTERIOR = "Medieval Interiors/Medieval_Interiors_Tiles"
TOWN = "Medieval T&C/Medieval_T&C_Tiles"

# The Interiors pack ships fourteen object sheets. Named here so the decor
# manifest below can draw on all of them rather than just the tavern.
CLUSTERS = "Medieval_Interiors_Update_ItemClusters.png"

# ---------------------------------------------------------------------------
# Ground textures: seamless 48x48 tiles drawn as the tile background.
#
# "cell" picks a plain tile straight off the sheet at (col, row).
# "auto" picks the fully-surrounded centre of an A1/A2 autotile block. Those
# blocks are 2 tiles wide by 3 tall, with the inner terrain split across the
# four quadrants of the lower 2x2 -- so the solid centre is the 48x48 square
# straddling that 2x2's midpoint, not any single cell.
# ---------------------------------------------------------------------------
GROUND = {
    "floor_wood":    (f"{INTERIOR}/A5_Medieval_Interior.png",  "cell", 1, 2),
    "floor_stone":   (f"{INTERIOR}/A5_Medieval_Interior.png",  "cell", 0, 0),
    "floor_dungeon": (f"{DUNGEON}/A5_Medieval_Dungeons.png",   "cell", 5, 3),
    "carpet":        (f"{INTERIOR}/A5_Medieval_Interior.png",  "cell", 1, 5),
    "grass":         (f"{TOWN}/A2_Medieval_T&C.png",           "cell", 0, 0),
    "road":          (f"{TOWN}/A2_Medieval_T&C.png",           "auto", 0, 3),
    "water":         (f"{TOWN}/A1_Medieval_T&C.png",           "auto", 0, 3),
    # A3 wall autotiles are 2x2 blocks whose top row is the capping edge and
    # bottom row the repeating body -- always take the body row.
    "wall_stone":    (f"{TOWN}/A3_Medieval_T&C.png",           "cell", 0, 3),
    "wall_inn":      (f"{TOWN}/A3_Medieval_T&C.png",           "cell", 8, 1),
    # Dungeon walls get their own darker, warmer brick so they read apart from
    # the neutral grey cobble floor.
    "wall_dungeon":  (f"{DUNGEON}/A5_Medieval_Dungeons.png",   "cell", 0, 9),
    # Buildings are seen from above, so their footprint is a roof, not a wall.
    "building":      (f"{TOWN}/A3_Medieval_T&C.png",           "cell", 12, 1),
}

# ---------------------------------------------------------------------------
# Props: free-size crops drawn as an overlay anchored bottom-centre on their
# tile, so they may overhang it. (sheet, x, y, w, h) in source pixels.
# ---------------------------------------------------------------------------
PROPS = {
    "door_wood": (f"{INTERIOR}/Animated Pieces/$Medieval_Interiors_Doors.png", 0, 0, 72, 96),
    "gate":      (f"{TOWN}/Animated Pieces/$Medieval_T&C_Doors.png",           0, 0, 72, 96),
    "inn":       (f"{TOWN}/Animated Pieces/$Medieval_T&C_Doors.png",          72, 0, 72, 96),
    "stairs":    (f"{DUNGEON}/Large Pieces/Medieval_Dungeons_Large_10.png",  240, 240, 96, 144),
    # A laid table rather than a bare one: the tavern's tables are the first
    # furniture anybody looks at, and Furniture_1's plain 96x96 slab read as a
    # crate. This one comes with plates, food and tankards already on it.
    "table":     (f"{INTERIOR}/{CLUSTERS}",                                  672,  4, 84, 140),
    "counter":   (f"{INTERIOR}/Tavern.png",                                    0, 384, 144, 96),
    "hearth":    (f"{INTERIOR}/Tavern.png",                                  384, 288, 144, 192),
}

# The job board is an NPC row in the seed data rather than a terrain, so it
# lives with the character art even though it is a piece of scenery.
SCENERY_NPCS = {
    "jobboard": (f"{INTERIOR}/Tavern.png", 480, 432, 48, 96),  # hanging notice banner
}

# ---------------------------------------------------------------------------
# Decor: loose scenery for the location art. Referenced as (sheet, index), where
# the index is the piece's position in `--catalog SHEET` output -- run that to
# see a numbered contact sheet. Indices are stable for a given sheet as long as
# catalog()'s scale/min_px/max_px stay put; re-run --catalog after changing them.
# ---------------------------------------------------------------------------
TOWN_1 = f"{TOWN}/Town_1.png"
OUTSIDE_1 = f"{TOWN}/Outside_1.png"
TAVERN = f"{INTERIOR}/Tavern.png"
DUNGEON_1 = f"{DUNGEON}/Medieval_Dungeons_1.png"
# The rest of the Interiors pack. Tavern.png alone was carrying every indoor
# scene, which is why the inn, the shop and the chapel all looked like the same
# room: eleven sheets of furniture were sitting unused next to it.
ITEM_CLUSTERS = f"{INTERIOR}/{CLUSTERS}"
FURNITURE_1 = f"{INTERIOR}/Furniture_1.png"
FURNITURE_2 = f"{INTERIOR}/Furniture_2.png"
SHOP = f"{INTERIOR}/Shop.png"
CHURCH = f"{INTERIOR}/Church.png"
PROFESSION_1 = f"{INTERIOR}/Profession_1.png"

DECOR = {
    # town: wells, market clutter, garden plots
    "well":          (TOWN_1, 16),
    "lamppost":      (TOWN_1, 11),
    "fountain":      (TOWN_1, 19),
    "barrel":        (TOWN_1, 20),
    "barrel_pile":   (TOWN_1, 41),
    "woodpile":      (TOWN_1, 9),
    "crate":         (TOWN_1, 28),
    "trellis":       (TOWN_1, 18),
    "market_stall":  (TOWN_1, 69),
    "washing_line":  (TOWN_1, 70),
    "garden_row":    (TOWN_1, 57),
    "signpost":      (TOWN_1, 79),
    "boulder":       (TOWN_1, 81),
    "produce":       (TOWN_1, 60),
    "sacks":         (TOWN_1, 12),
    # countryside
    "tree_pine":     (OUTSIDE_1, 33),
    "tree_oak":      (OUTSIDE_1, 35),
    "tree_birch":    (OUTSIDE_1, 29),
    "bush":          (OUTSIDE_1, 36),
    "shrub":         (OUTSIDE_1, 32),
    "flowers":       (OUTSIDE_1, 1),
    "grass_tuft":    (OUTSIDE_1, 2),
    "stump":         (OUTSIDE_1, 38),
    "rocks":         (OUTSIDE_1, 20),
    # inn
    "chair":         (TAVERN, 5),
    "chair_tall":    (TAVERN, 7),
    "cabinet":       (TAVERN, 28),
    "bookshelf":     (TAVERN, 46),
    "keg":           (TAVERN, 43),
    "crate_wood":    (TAVERN, 52),
    "lute":          (TAVERN, 47),
    "cauldron":      (TAVERN, 0),
    "cartwheel":     (TAVERN, 37),
    "hanging_lamp":  (TAVERN, 29),
    "wall_banner":   (TAVERN, 57),
    "plates":        (TAVERN, 65),
    # dungeon
    "column":        (DUNGEON_1, 31),
    "brazier":       (DUNGEON_1, 46),
    "candelabra":    (DUNGEON_1, 20),
    "candles":       (DUNGEON_1, 43),
    "skull":         (DUNGEON_1, 4),
    "bones":         (DUNGEON_1, 8),
    "skeleton_pile": (DUNGEON_1, 12),
    "sarcophagus":   (DUNGEON_1, 18),
    "gravestone":    (DUNGEON_1, 27),
    "pentagram":     (DUNGEON_1, 37),
    "urn":           (DUNGEON_1, 56),
    "straw_mat":     (DUNGEON_1, 15),
    "statue":        (DUNGEON_1, 26),

    # ---- Interiors: tables and set pieces (ItemClusters) ----
    # Whole arrangements rather than single objects — a table already laid with
    # plates and food, benches included. These are what the tavern wanted.
    "dining_set":      (ITEM_CLUSTERS, 0),   # stone table, two high-backed chairs
    "table_laden":     (ITEM_CLUSTERS, 3),   # also the `table` prop above
    "table_long":      (ITEM_CLUSTERS, 4),
    "banquet_set":     (ITEM_CLUSTERS, 5),
    "feast_table":     (ITEM_CLUSTERS, 10),
    "feast_table_set": (ITEM_CLUSTERS, 11),
    "plant_display":   (ITEM_CLUSTERS, 24),
    "workbench":       (ITEM_CLUSTERS, 25),
    "treasure_table":  (ITEM_CLUSTERS, 26),
    "apothecary_shelf": (ITEM_CLUSTERS, 27),
    "tool_bench":      (ITEM_CLUSTERS, 28),
    "scrying_orbs":    (ITEM_CLUSTERS, 29),
    "table_plain":     (ITEM_CLUSTERS, 30),
    "cabinet_tall":    (ITEM_CLUSTERS, 31),
    "sideboard":       (ITEM_CLUSTERS, 32),
    "chess_table":     (ITEM_CLUSTERS, 33),
    "alchemy_bench":   (ITEM_CLUSTERS, 34),
    "butcher_table":   (ITEM_CLUSTERS, 35),
    "dish_dresser":    (ITEM_CLUSTERS, 36),
    "cabinet_stone":   (ITEM_CLUSTERS, 37),
    "lectern":         (ITEM_CLUSTERS, 38),
    "bakers_table":    (ITEM_CLUSTERS, 39),
    "scholars_table":  (ITEM_CLUSTERS, 40),
    "alchemist_table": (ITEM_CLUSTERS, 41),
    "writing_desk":    (ITEM_CLUSTERS, 42),
    "shelf_narrow":    (ITEM_CLUSTERS, 43),
    "desk":            (ITEM_CLUSTERS, 44),
    "table_round":     (ITEM_CLUSTERS, 45),
    "side_table":      (ITEM_CLUSTERS, 46),
    "table_small":     (ITEM_CLUSTERS, 47),
    "reagents":        (ITEM_CLUSTERS, 20),
    "potions":         (ITEM_CLUSTERS, 22),
    "herbs":           (ITEM_CLUSTERS, 23),

    # ---- Interiors: bedrooms (Furniture_1) ----
    # There was no bed in the game at all before this.
    "bed_double":    (FURNITURE_1, 0),
    "bed_single":    (FURNITURE_1, 2),
    "nightstand":    (FURNITURE_1, 3),
    "drawers":       (FURNITURE_1, 5),
    "console_table": (FURNITURE_1, 8),
    "dresser":       (FURNITURE_1, 11),
    "wardrobe":      (FURNITURE_1, 16),
    "bed_large":     (FURNITURE_1, 18),
    "clock_tall":    (FURNITURE_1, 19),
    "bed_wood":      (FURNITURE_1, 24),
    "wardrobe_open": (FURNITURE_1, 31),
    "mirror":        (FURNITURE_1, 34),
    "vanity":        (FURNITURE_1, 38),

    # ---- Interiors: halls (Furniture_2) ----
    "table_refectory": (FURNITURE_2, 0),
    "bench_carved":    (FURNITURE_2, 2),
    "table_hall":      (FURNITURE_2, 4),
    "pew":             (FURNITURE_2, 7),
    "table_banquet":   (FURNITURE_2, 11),
    "display_case":    (FURNITURE_2, 15),
    "rug_hide":        (FURNITURE_2, 16),
    "rug_bear":        (FURNITURE_2, 18),
    "chair_high":      (FURNITURE_2, 24),
    "bookshelf_wide":  (FURNITURE_2, 27),
    "rug_red":         (FURNITURE_2, 29),
    "bookshelf_tall":  (FURNITURE_2, 30),
    "rug_square":      (FURNITURE_2, 34),

    # ---- Interiors: the rest of the tavern sheet ----
    # Twelve of its eighty-eight pieces were being used.
    "table_tavern":  (TAVERN, 20),
    "fireplace":     (TAVERN, 33),
    "barrel_rack":   (TAVERN, 43),
    "cupboard":      (TAVERN, 46),
    "shelf_crate":   (TAVERN, 52),
    "shelf_wall":    (TAVERN, 60),
    "shelf_tall":    (TAVERN, 66),
    "shelf_wide":    (TAVERN, 69),
    "shelf_stone":   (TAVERN, 81),
    "hanging_meat":  (TAVERN, 82),
    "hanging_game":  (TAVERN, 74),
    "roast_pig":     (TAVERN, 87),
    "bread":         (TAVERN, 31),
    "cheese":        (TAVERN, 18),
    "ham":           (TAVERN, 16),
    "tankard":       (TAVERN, 34),
    "cake":          (TAVERN, 85),
    "stockpot":      (TAVERN, 23),
    "knives":        (TAVERN, 63),
    "painting":      (TAVERN, 32),

    # ---- Interiors: shops and workshops ----
    "shop_crates":     (SHOP, 1),
    "still":           (SHOP, 2),
    "shop_counter":    (SHOP, 23),
    "cabinet_corner":  (SHOP, 26),
    "display_cabinet": (SHOP, 34),
    "chest_open":      (SHOP, 37),
    "chest":           (SHOP, 40),
    "books_open":      (SHOP, 66),
    "books_row":       (SHOP, 67),
    "flowerpot":       (SHOP, 76),
    "potted_plant":    (SHOP, 86),
    "plant_reeds":     (SHOP, 88),
    "flower_bowl":     (SHOP, 89),
    "crates":          (SHOP, 91),
    "shop_shelf":      (SHOP, 93),
    "apothecary_wall": (SHOP, 94),
    "crate_stack":     (SHOP, 95),
    "crate_pile":      (SHOP, 104),
    "lantern":         (SHOP, 108),
    "scales":          (SHOP, 18),

    # ---- Interiors: chapel ----
    "altar":         (CHURCH, 1),
    "candlestick":   (CHURCH, 2),
    "stained_glass": (CHURCH, 4),
    "pew_long":      (CHURCH, 5),
    "coffin":        (CHURCH, 10),
    "altar_stone":   (CHURCH, 16),
    "throne":        (CHURCH, 23),
    "stone_bench":   (CHURCH, 26),
    "throne_ornate": (CHURCH, 27),
    "window_rose":   (CHURCH, 36),

    # ---- Interiors: crafts ----
    "spinning_wheel": (PROFESSION_1, 0),
    "mannequin":      (PROFESSION_1, 14),
    "chest_iron":     (PROFESSION_1, 92),
    "chests":         (PROFESSION_1, 97),
    "loom":           (PROFESSION_1, 107),
    "chest_pair":     (PROFESSION_1, 108),
    "craft_bench":    (PROFESSION_1, 110),
}

# ---------------------------------------------------------------------------
# Animated pieces: fire, torches, candles, orbs, smoke.
#
# The packs ship these as RPG Maker "$" character sheets — one object laid out
# as 3 animation columns by 4 variant rows, cell = width/3 by height/4. Some
# sheets use only the first row or two; the empty ones are skipped.
#
# Each entry cuts one row into a single horizontal strip of 3 frames, written to
# assets/images/anim/. The strip is the unit the renderer animates, so all three
# frames must share a bounding box — trimming them individually would make the
# flame jump around as it played.
# ---------------------------------------------------------------------------
ANIM_COLS = 3
ANIM_ROWS = 4

I_ANIM = f"{INTERIOR}/Animated Pieces"
D_ANIM = f"{DUNGEON}/Animated Pieces"
T_ANIM = f"{TOWN}/Animated Pieces"
W_ANIM = "Medieval Warfare/Medieval_Warfare_Tiles/Animated Pieces"

ANIMATED = {
    # hearth and camp fire
    "fire":            (f"{I_ANIM}/$Medieval_Interiors_Fire.png", 0),
    "fire_low":        (f"{I_ANIM}/$Medieval_Interiors_Fire.png", 1),
    "campfire":        (f"{W_ANIM}/$Medieval_Warfare_Lightsource_3.png", 0),
    "campfire_low":    (f"{W_ANIM}/$Medieval_Warfare_Lightsource_3.png", 3),
    # dungeon torches and braziers
    "brazier_wall":    (f"{D_ANIM}/$!Medieval_Dungeons_Lightsource_1.png", 0),
    "brazier_lit":     (f"{D_ANIM}/$!Medieval_Dungeons_Lightsource_1.png", 1),
    "brazier_stone":   (f"{D_ANIM}/$!Medieval_Dungeons_Lightsource_1.png", 2),
    "brazier_pale":    (f"{D_ANIM}/$!Medieval_Dungeons_Lightsource_1.png", 3),
    "torch_pillar":    (f"{D_ANIM}/$!Medieval_Dungeons_Lightsource_2.png", 0),
    "candelabra_lit":  (f"{D_ANIM}/$!Medieval_Dungeons_Lightsource_2.png", 1),
    "candelabra_iron": (f"{W_ANIM}/$Medieval_Warfare_Lightsource_1.png", 0),
    "chandelier":      (f"{D_ANIM}/$!Medieval_Dungeons_Lightsource_3.png", 0),
    "chandelier_ring": (f"{D_ANIM}/$!Medieval_Dungeons_Lightsource_4.png", 0),
    # interior candles
    "candles_tall":    (f"{I_ANIM}/$Medieval_Interiors_UPDATE_AnimatedCandles_1.png", 0),
    "candle_sconce":   (f"{I_ANIM}/$Medieval_Interiors_UPDATE_AnimatedCandles_1.png", 1),
    "candle_slim":     (f"{I_ANIM}/$Medieval_Interiors_UPDATE_AnimatedCandles_1.png", 2),
    "candle_stand":    (f"{I_ANIM}/$Medieval_Interiors_UPDATE_AnimatedCandles_1.png", 3),
    "candle_gold":     (f"{I_ANIM}/$Medieval_Interiors_UPDATE_AnimatedCandles_2.png", 0),
    "lantern_lit":     (f"{I_ANIM}/$Medieval_Interiors_UPDATE_AnimatedCandles_2.png", 1),
    # magic
    "orb_blue":        (f"{D_ANIM}/$!Medieval_Dungeons_Orbs_1.png", 1),
    "orb_pink":        (f"{D_ANIM}/$!Medieval_Dungeons_Orbs_1.png", 2),
    "orb_pedestal":    (f"{D_ANIM}/$!Medieval_Dungeons_Orbs_5.png", 1),
    "orb_pedestal_violet": (f"{D_ANIM}/$!Medieval_Dungeons_Orbs_5.png", 3),
    "orb_staff":       (f"{D_ANIM}/$!Medieval_Dungeons_Orbs_9.png", 0),
    # outdoors
    "smoke":           (f"{T_ANIM}/$Medieval_T&C_Smoke.png", 0),
}


# ---------------------------------------------------------------------------
# Buildings. Whole premade houses, drawn in 3/4 view: the ground floor sits in
# the bottom third of the image and the roof rises above it. They are placed by
# tools/build_maps.py, which stamps a solid footprint under each one and anchors
# the image to the bottom of that footprint so the elevation overhangs upward.
#
# These are already individual files, so there is nothing to slice — they are
# just trimmed and copied. The tile size printed by --preview is the *image*
# size; the walkable footprint is declared separately in build_maps.py.
# ---------------------------------------------------------------------------
TOWN_PIECES = f"{TOWN}/Town Pieces"

BUILDINGS = {
    "house_tower":    f"{TOWN_PIECES}/Building_2.png",     # 8x17
    "house_round":    f"{TOWN_PIECES}/Building_10.png",    # 9x17
    "house_turret":   f"{TOWN_PIECES}/Building_11.png",    # 9x15
    "house_gabled":   f"{TOWN_PIECES}/Building_13.png",    # 11x14
    "house_double":   f"{TOWN_PIECES}/Building_12.png",    # 12x14
    "house_timber":   f"{TOWN_PIECES}/Building_1.png",     # 13x14
    "house_narrow":   f"{TOWN_PIECES}/Building_21.png",    # 7x14
    "hall_long":      f"{TOWN_PIECES}/Building_24.png",    # 16x13
    "hall_wide":      f"{TOWN_PIECES}/Building_25.png",    # 17x14
    "house_tall":     f"{TOWN_PIECES}/Building_26.png",    # 16x16
}

# ---------------------------------------------------------------------------
# Characters. PVGames ships two walk sheets per actor, each 8 frames x 4
# directions of 128px frames: `_walking` holds the cardinals (down, left,
# right, up) and `_walking_diag` the diagonals.
#
# The diagonal sheet's row order is not documented. It was recovered by
# mirroring each row and matching it against the others: the method pairs the
# cardinal sheet correctly (S with itself, W with E, N with itself), and pairs
# the diagonal rows 0-with-2 and 1-with-3, which — together with row 0 reading
# as west-facing — leaves only one possible order: SW, NW, SE, NE.
#
# We stitch both into one 4 frame x 8 facing sheet and thin the cycle to every
# other frame, so the client can animate it with background-position alone:
# column = walk frame, row = facing.
# ---------------------------------------------------------------------------
DOWN_ROW = 0
# The whole cycle, not every other frame.
#
# The packs ship eight frames per facing and this used to take four, so the game
# animated a half-rate walk cut from art that already had the rest. Doubling it
# costs nothing but sheet width.
#
# Must match slice_paperdoll.py and bake_paperdoll.py, and the client's
# WALK_FRAME_COUNT and `background-size` — five views of the same number.
WALK_FRAMES = list(range(8))
CARDINAL_ROWS = 4
DIRECTIONS = 8                  # S, W, E, N, SW, NW, SE, NE -- sheet row order
# These caps are set by the device pixels their CSS box actually demands, not by
# what the pack happens to hold. The game is played at a 1.5x device pixel
# ratio, so a box N CSS px wide needs 1.5N real pixels behind it.
#
#   FACE_PX   .party-face is 74px -> 111 needed, .avatar-option 72px -> 108,
#             .camp-face 96px -> 144. 96 was short for all three. 144 is also
#             exactly FACE_CELL, the pack's own cell, so this is a clean crop
#             with no resampling at all.
#   BUST_PX   .dlg-bust 186px -> 279 needed and .sheet-bust 190px -> 285, against
#             320. Adequate but a thin margin, and object-fit: cover crops into
#             it. The source busts are 512 square.
#   BATTLER_PX  deliberately unchanged. .cbt-battler is max-height 220px -> 330
#             needed, so 512 already carries 1.55x headroom. Going to the pack's
#             1024 would be invisible and would add ~30 MB across 41 files.
FACE_PX = 144                   # faceset size used in the sidebar / sheet
BUST_PX = 512                   # bust size used in dialogue
BATTLER_PX = 512                # battle art, down from the pack's 1024 square
BUST_EXPRESSIONS = 8            # _Bust_1 .. _Bust_8 is the most any actor ships

BOSSES = "Medieval Bosses"
HEROES = "Medieval Heroes I"
MONSTERS_DIR = "Medieval_Dungeons_Monsters"
TOWNFOLK = "Medieval_Townfolk1"
WARFARE = "Medieval Warfare/Medieval_Warfare_Humans"
TC_HUMANS = "Medieval T&C/Medieval_T&C_Humans"
TC_CREATURES = "Medieval T&C/Medieval_T&C_Creatures"

WARFARE_PC = f"{WARFARE}/Medieval_Warfare_PremadeCharacters"
INTERIOR_PC = "Medieval Interiors/Medieval_Interiors_Humans/Medieval_Interiors_PremadeCharacters"
TC_PC = f"{TC_HUMANS}/Medieval_T&C_PremadeCharacters"

# Actors are (folder,) or (folder, sheet suffix) -- the filename prefix is read
# off disk by stem_for(). Most have a walk cycle; the bat only ever flies.

# One sprite per SRD class, keyed by classes.sprite_key in the database. The
# armoured Warfare premades suit the martial classes; the robed Interiors and
# Townfolk civilians read far better for casters and skirmishers.
CLASS_SPRITES = {
    "fighter":   (f"{HEROES}/Baenor",),
    "paladin":   (f"{HEROES}/LordEsther",),
    "cleric":    (f"{HEROES}/Leyanne",),
    "wizard":    (f"{HEROES}/MasterGaerron",),
    "sorcerer":  (f"{HEROES}/Naia",),
    "ranger":    (f"{HEROES}/Huntress",),
    "barbarian": (f"{TOWNFOLK}/Medieval_Townfolk_Male_Blacksmith",),
    "bard":      (f"{TOWNFOLK}/Medieval_Townfolk_Female_Bard",),
    "rogue":     (f"{INTERIOR_PC}/Medieval_Interiors_Premade_Male_2",),
    "druid":     (f"{INTERIOR_PC}/Medieval_Interiors_Premade_Male_3",),
    "monk":      (f"{INTERIOR_PC}/Medieval_Interiors_Premade_Male_5",),
    "warlock":   (f"{INTERIOR_PC}/Medieval_Interiors_Premade_Female_4",),
}

# Everyone a player can meet, stand next to, or wear as an avatar.
#
# Keys are descriptive rather than named after the pack folder, because a
# sprite_key ends up in an asset path and in the avatars table, and neither
# should have to change if the art behind it is ever swapped.
NPCS = {
    "innkeeper":  (f"{TOWNFOLK}/Medieval_Townfolk_Female_Barkeep",),
    "merchant":   (f"{TOWNFOLK}/Medieval_Townfolk_Female_Merchant",),
    "captain":    (f"{HEROES}/PaulHammerArm",),
    "cleric":     (f"{HEROES}/Leyanne",),

    # Captain Elowen Reed. Her own key rather than sharing `cleric`, even though
    # both are cut from Leyanne today: a key ends up in an asset path and in the
    # DB, so giving her one now is what lets her art be replaced later without
    # touching content. She had PaulHammerArm — a man — and she is written with
    # she/her throughout, in a dialogue that uses expressions 2..7. Leyanne is
    # one of only five actors in the packs that ship eight busts, and the only
    # female one not already spoken for by Ilse, so this is the single
    # substitution that fixes the gender without costing her performance.
    "elowen":     (f"{HEROES}/Leyanne",),

    # Nakka Skarn, the goblin warlord. NPC sprite keys resolve under
    # assets/images/npcs/, so the goblin has to be cut in here as well as into
    # monsters/ -- otherwise load_content.py cannot find her four files.
    "goblin_warlord": (f"{MONSTERS_DIR}/Medieval_Dungeons_Goblin",),

    # Townfolk. The ones with a trade read as somebody with a job, which is
    # what most of Rivermark needs; the children and the beggar exist so a
    # street is not made entirely of shopkeepers.
    "baker":      (f"{TOWNFOLK}/Medieval_Townfolk_Male_Baker",),
    "farmer":     (f"{TOWNFOLK}/Medieval_Townfolk_Male_Farmer",),
    "miner":      (f"{TOWNFOLK}/Medieval_Townfolk_Male_Miner",),
    "smith":      (f"{TOWNFOLK}/Medieval_Townfolk_Male_Blacksmith",),
    "scribe":     (f"{TOWNFOLK}/Medieval_Townfolk_Male_Scribe",),
    "jester":     (f"{TOWNFOLK}/Medieval_Townfolk_Female_Jester",),
    "sweeper":    (f"{TOWNFOLK}/Medieval_Townfolk_Female_Sweeper",),
    "beggar":     (f"{TOWNFOLK}/Medieval_Townfolk_Female_Beggar",),
    "skulker":    (f"{TOWNFOLK}/Medieval_Townfolk_Male_Skulker",),
    "child_1":    (f"{TOWNFOLK}/Medieval_Townfolk_Child_1",),
    "child_2":    (f"{TOWNFOLK}/Medieval_Townfolk_Child_2",),

    # Heroes not already spoken for by a class. Saurial is the only non-human
    # silhouette in the roster, which makes him worth having for a dragonborn
    # or lizardfolk character.
    "saurial":    (f"{HEROES}/Saurial",),

    # Brother Aldric's own look, rather than sharing Leyanne's `cleric` art with
    # the class default. Named for the character because that is what it is for;
    # a T&C premade has no describable feature to name it after.
    #
    # This folder is one of the ones with an inconsistent prefix — its walk
    # sheets say Medieval_TC_ and its portraits Medieval_T&C_ — which
    # actor_file() already falls back for.
    "aldric":     (f"{TC_PC}/Medieval_T&C_Premade_Male_1",),

    # Dungeon prisoners: ragged, unarmed, and the only art that reads as a
    # captive rather than a combatant.
    "prisoner_1": ("Medieval_Dungeons_Characters/Medieval_Dungeons_Prisoner_1",),
    "prisoner_2": ("Medieval_Dungeons_Characters/Medieval_Dungeons_Prisoner_2",),
    "prisoner_3": ("Medieval_Dungeons_Characters/Medieval_Dungeons_Prisoner_3",),
}

# Art keys describe the picture, not the stat block. The creature's NAME lives
# in content/monsters/*.json, so an SRD Gnoll and the sheet it wears are free to
# be renamed independently -- which matters, because three of the original ten
# keys (skeleton, zombie, orc) were named after creatures the art does not
# depict and the packs do not contain.
MONSTERS = {
    "goblin":     (f"{MONSTERS_DIR}/Medieval_Dungeons_Goblin",),
    "kobold":     (f"{MONSTERS_DIR}/Medieval_Dungeons_Kobold",),
    "ogre":       (f"{MONSTERS_DIR}/Medieval_Dungeons_Ogre",),
    "giant_rat":  (f"{MONSTERS_DIR}/Medieval_Dungeons_RodentofUnusualSize",),
    "wolf":       (f"{TC_CREATURES}/Medieval_T&C_WolfTimber",),
    "stirge":     (f"{MONSTERS_DIR}/Medieval_Dungeons_GiantBat", "flying"),
    "gnoll":      (f"{MONSTERS_DIR}/Medieval_Dungeons_Gnoll",),
    "ratman":     (f"{MONSTERS_DIR}/Medieval_Dungeons_Ratman",),
    "bugbear":    (f"{MONSTERS_DIR}/Medieval_Dungeons_Bugbear",),
    "bandit":     (f"{TOWNFOLK}/Medieval_Townfolk_Male_Skulker",),

    # The rest of the dungeon bestiary. Every one ships a battler and a
    # portrait, so they arrive combat-ready rather than needing art later.
    "balor":      (f"{MONSTERS_DIR}/Medieval_Dungeons_Balron",),
    "rock_man":   (f"{MONSTERS_DIR}/Medieval_Dungeons_RockMan",),
    "crawler":    (f"{MONSTERS_DIR}/Medieval_Dungeons_Crawler",),
    "manticore":  (f"{MONSTERS_DIR}/Medieval_Dungeons_Manticore",),
    "dire_wolf":  (f"{MONSTERS_DIR}/Medieval_Dungeons_Waheela",),
    "golem":      (f"{MONSTERS_DIR}/Medieval_Dungeons_Golem",),
    "dragon":     (f"{MONSTERS_DIR}/Medieval_Dungeons_Dragon_Black",),
    "griffon":    (f"{MONSTERS_DIR}/Medieval_Dungeons_Griffin",),
    "howler":     (f"{MONSTERS_DIR}/Medieval_Dungeons_Howler",),
    "chimera":    (f"{MONSTERS_DIR}/Medieval_Dungeons_Chimera",),
    "gremlin":    (f"{MONSTERS_DIR}/Medieval_Dungeons_Gremlin",),
    "gorgon":     (f"{MONSTERS_DIR}/Medieval_Dungeons_Gorgon",),
    "minotaur":   (f"{MONSTERS_DIR}/Medieval_Dungeons_Minotaur",),
    "tentacled":  (f"{MONSTERS_DIR}/Medieval_Dungeons_Flayer",),
    "ooze":       (f"{MONSTERS_DIR}/Medieval_Dungeons_Slime",),

    # Beasts. No busts in the pack -- emit_actor already tolerates that, and a
    # wolf has no dialogue to need one.
    "black_wolf": (f"{TC_CREATURES}/Medieval_T&C_WolfBlack",),
    "dog":        (f"{TC_CREATURES}/Medieval_T&C_Dog1",),
    "guard_dog":  (f"{TC_CREATURES}/Medieval_T&C_Dog2",),
    "boar":       (f"{TC_CREATURES}/Medieval_T&C_Pig1",),
    "deer":       (f"{TC_CREATURES}/Medieval_T&C_Buck",),
    "cow":        (f"{TC_CREATURES}/Medieval_T&C_Cow1",),
}

# ---------------------------------------------------------------------------
# Bosses. Kept apart from MONSTERS so an encounter cannot reach for one by
# accident -- these are the only actors in the packs with no ordinary use, and
# putting a Sunken God in a random tunnel fight would be a content bug the
# loader has no way to catch.
# ---------------------------------------------------------------------------
BOSS_SPRITES = {
    "boss_prince":   (f"{BOSSES}/Medieval_Bosses_PrinceTaerron",),
    "boss_witch":    (f"{BOSSES}/Medieval_Bosses_Witch",),
    "boss_worm":     (f"{BOSSES}/Medieval_Bosses_Gollageth",),
    "boss_hive":     (f"{BOSSES}/Medieval_Bosses_Hive",),
    "boss_drowned":  (f"{BOSSES}/Medieval_Bosses_SunkenGod",),
    "boss_triplets": (f"{BOSSES}/Medieval_Bosses_TheTriplets",),
    "boss_wizard":   (f"{BOSSES}/Medieval_Bosses_Haelerion",),
    "boss_king":     (f"{BOSSES}/Medieval_Bosses_TheOldKing",),
}

# ---------------------------------------------------------------------------
# Battlers: the single front-facing 1024px painting each dungeon monster ships,
# meant to be shown at the top of a fight rather than walked around a map.
#
# The manifest is MONSTERS itself, not a copy of it: every creature we can fight
# is already listed there, and a second list would only be somewhere for the two
# to drift apart. The Townfolk and Heroes packs ship no battlers at all, which is
# why the player's own classes are not in here.
# ---------------------------------------------------------------------------
BATTLERS = {**MONSTERS, **BOSS_SPRITES}

# ---------------------------------------------------------------------------
# Combat clips. Each actor folder carries up to 34 `_MVsv_alt_*.png` sheets --
# RPG Maker MV side-view battler poses, 3 frames laid out left to right.
#
# The frame size is *not* fixed at 128px: the ogre's sheets are 576x192 and the
# golem's 768x256, so the frame is always width/3, read off the sheet, never
# assumed. Like the animated scenery, all three frames are cropped to a shared
# bounding box.
#
# Six clips are enough to narrate a round -- swing, cast, loose, fall, cheer,
# wait -- and cutting all 34 would be some 850 files for art the game never
# reaches.
# ---------------------------------------------------------------------------
MVSV_FRAMES = 3
MVSV = ("attack1", "magic", "shooting", "dead1", "victory1", "stance1")

# Everyone who can turn up in a fight. Merged into one dict rather than iterated
# as three, because `cleric` appears in both CLASS_SPRITES and NPCS pointing at
# the same folder and would otherwise be cut twice.
MVSV_ACTORS = {**CLASS_SPRITES, **NPCS, **MONSTERS, **BOSS_SPRITES}


def sheet(rel):
    path = os.path.join(PACK, rel)
    if not os.path.exists(path):
        raise FileNotFoundError(path)
    return Image.open(path).convert("RGBA")


def stem_for(folder, suffix="walking"):
    """
    Work out an actor's file prefix from its folder.

    The packs are not consistent about this -- the Timber Wolf's folder is
    Medieval_T&C_WolfTimber but its files say Medieval_TC_TimberWolf, and the
    Townfolk drop the gender from their filenames -- so read the prefix off the
    animation sheet that is actually on disk rather than guessing from the path.
    """
    hits = glob.glob(os.path.join(PACK, folder, f"*_{suffix}.png"))
    hits = [h for h in hits if not h.endswith(f"_{suffix}_diag.png")]
    if not hits:
        raise FileNotFoundError(f"no *_{suffix}.png in {folder}")
    return os.path.basename(hits[0])[: -len(f"_{suffix}.png")]


def actor_file(folder, stem, name):
    """
    Path to one of an actor's still images, tolerating a change of prefix.

    A folder is usually consistent with itself, but the Timber Wolf is not: its
    animation sheets say Medieval_TC_TimberWolf while its face and battler say
    Medieval_T&C_TimberWolf, so the stem read off the walk cycle finds neither.
    Fall back to matching the suffix alone -- a folder holds one actor, so there
    is nothing else the file could belong to.
    """
    rel = f"{folder}/{stem}_{name}"
    if os.path.exists(os.path.join(PACK, rel)):
        return rel
    hits = sorted(glob.glob(os.path.join(PACK, folder, f"*_{name}")))
    if not hits:
        raise FileNotFoundError(os.path.join(PACK, rel))
    return f"{folder}/{os.path.basename(hits[0])}"


def trim(im):
    """Crop away fully transparent margins so props sit flush on their tile."""
    box = im.getbbox()
    return im.crop(box) if box else im


def cut_ground(spec):
    src, kind, a, b = spec
    im = sheet(src)
    if kind == "cell":
        return im.crop((a * TILE, b * TILE, (a + 1) * TILE, (b + 1) * TILE))
    if kind == "auto":
        # centre of the block's lower 2x2, offset half a tile into it
        x = a * TILE + TILE // 2
        y = (b + 1) * TILE + TILE // 2
        return im.crop((x, y, x + TILE, y + TILE))
    raise ValueError(f"unknown ground kind: {kind}")


def cut_prop(spec):
    src, x, y, w, h = spec
    return trim(sheet(src).crop((x, y, x + w, y + h)))


# ---------------------------------------------------------------------------
# Autotile edges: the transitions the packs shipped and the game never cut.
#
# cut_ground's "auto" mode takes the solid centre of an A2 block and discards
# the rest — which is most of the block, and the whole reason it exists. An A2
# block is 2x3 cells: the top-left cell holds the four concave inner-corner
# quarters, and the lower 2x2 is a rounded blob of the terrain whose border
# carries every edge and convex corner, feathered into the ground it sits on.
# PVGames paints those edges with the grass itself, so a composed boundary tile
# blends into a plain grass neighbour with no seam. Cutting only the centre is
# why every road in Rivermark ends in a hard right angle.
#
# Composition is the standard quarter-tile scheme: a 48px tile is four 24px
# quadrants, and each quadrant is decided by the three neighbours that touch
# it. Five possible pieces per quadrant — interior, the two edges, convex
# corner, concave corner — drawn from the block.
#
# The neighbour mask is one byte: N=1 E=2 S=4 W=8 NE=16 SE=32 SW=64 NW=128, a
# set bit meaning "the neighbour is my terrain". A diagonal only matters when
# both of its cardinals are set (otherwise its quadrant is already an edge or
# corner), so masks are normalised by forcing irrelevant diagonals to 1. That
# collapses 256 masks to the classic 47 distinct tiles; 255 is the interior
# and stays the plain ground tile. tools/../assets/js/game.js computes the
# same mask with the same normalisation — the filename is the contract.
# ---------------------------------------------------------------------------
AUTOTILES = {
    # terrain -> (sheet, block cell col, block cell row)
    "road": (f"{TOWN}/A2_Medieval_T&C.png", 0, 3),
}


def normalize_edge_mask(m):
    if not ((m & 1) and (m & 2)):
        m |= 16    # NE
    if not ((m & 2) and (m & 4)):
        m |= 32    # SE
    if not ((m & 4) and (m & 8)):
        m |= 64    # SW
    if not ((m & 8) and (m & 1)):
        m |= 128   # NW
    return m


def cut_autotile_edges(spec):
    """Every composed edge tile for one A2 block, as {mask: 48px Image}."""
    src, a, b = spec
    im = sheet(src)
    bx, by = a * TILE, b * TILE
    H = TILE // 2
    island = im.crop((bx, by, bx + TILE, by + TILE))
    blob = im.crop((bx, by + TILE, bx + 2 * TILE, by + 3 * TILE))

    def bq(cx, cy):
        return blob.crop((cx * H, cy * H, (cx + 1) * H, (cy + 1) * H))

    def iq(cx, cy):
        return island.crop((cx * H, cy * H, (cx + 1) * H, (cy + 1) * H))

    # Per quadrant: which piece for each neighbour situation. `e1` shows the
    # terrain ending on the quadrant's horizontal side (used when the N/S
    # cardinal is missing), `e2` on its vertical side (E/W missing).
    QUADS = {
        # (qx, qy): (cardinal1 bit, cardinal2 bit, diagonal bit, pieces)
        (0, 0): (1, 8, 128, dict(full=bq(1, 1), e1=bq(1, 0), e2=bq(0, 1), outer=bq(0, 0), inner=iq(0, 0))),
        (1, 0): (1, 2, 16,  dict(full=bq(2, 1), e1=bq(2, 0), e2=bq(3, 1), outer=bq(3, 0), inner=iq(1, 0))),
        (0, 1): (4, 8, 64,  dict(full=bq(1, 2), e1=bq(1, 3), e2=bq(0, 2), outer=bq(0, 3), inner=iq(0, 1))),
        (1, 1): (4, 2, 32,  dict(full=bq(2, 2), e1=bq(2, 3), e2=bq(3, 2), outer=bq(3, 3), inner=iq(1, 1))),
    }

    out = {}
    for m in range(256):
        if normalize_edge_mask(m) != m or m == 255:
            continue
        tile = Image.new("RGBA", (TILE, TILE))
        for (qx, qy), (c1, c2, d, p) in QUADS.items():
            has1, has2, hasd = m & c1, m & c2, m & d
            if has1 and has2:
                piece = p["full"] if hasd else p["inner"]
            elif has1:
                piece = p["e2"]
            elif has2:
                piece = p["e1"]
            else:
                piece = p["outer"]
            tile.paste(piece, (qx * H, qy * H))
        out[m] = tile
    return out


_catalog_cache = {}


def cut_decor(src, index):
    """One piece off an object sheet, by its index in catalog(src)."""
    if src not in _catalog_cache:
        _catalog_cache[src] = catalog_boxes(src)
    boxes = _catalog_cache[src]
    if index >= len(boxes):
        raise IndexError(f"{src}: index {index} of {len(boxes)} pieces")
    x, y, w, h = boxes[index]
    return trim(sheet(src).crop((x, y, x + w, y + h)))


def cut_animated(src, row):
    """
    One variant row of a "$" sheet, as a horizontal strip of ANIM_COLS frames.

    The frames are cropped to a *shared* bounding box — the union of all three —
    rather than trimmed individually. Trimming each frame to its own content
    would give them different sizes and offsets, and a flame animated that way
    lurches around its own base instead of flickering in place.
    """
    im = sheet(src)
    cw, ch = im.width // ANIM_COLS, im.height // ANIM_ROWS
    frames = [im.crop((i * cw, row * ch, (i + 1) * cw, (row + 1) * ch))
              for i in range(ANIM_COLS)]

    box = None
    for f in frames:
        b = f.getbbox()
        if b is None:
            continue
        box = b if box is None else (min(box[0], b[0]), min(box[1], b[1]),
                                     max(box[2], b[2]), max(box[3], b[3]))
    if box is None:
        raise ValueError(f"{src} row {row} is empty")

    fw, fh = box[2] - box[0], box[3] - box[1]
    strip = Image.new("RGBA", (fw * ANIM_COLS, fh))
    for i, f in enumerate(frames):
        strip.paste(f.crop(box), (i * fw, 0))
    return strip, fw, fh


def cut_char(folder, stem, frame, row=DOWN_ROW, suffix="walking"):
    im = sheet(f"{folder}/{stem}_{suffix}.png")
    x, y = frame * CHAR, row * CHAR
    return trim(im.crop((x, y, x + CHAR, y + CHAR)))


def cut_walk_sheet(folder, stem, suffix="walking", frames=None):
    """
    Stitch the cardinal and diagonal sheets into N frames x 8 facings.

    Frames keep their full 128px cell rather than being trimmed: the client
    addresses them by background-position, so every cell has to stay the same
    size and stay aligned to the character's feet. An actor with no diagonal
    sheet falls back to its cardinals, which just means it turns in quarters.

    `suffix` and `frames` are what let this cut the idle loops as well as the
    walk: same geometry, different source sheet and a shorter cycle.
    """
    frames = WALK_FRAMES if frames is None else frames
    cardinal = sheet(f"{folder}/{stem}_{suffix}.png")
    try:
        diagonal = sheet(f"{folder}/{stem}_{suffix}_diag.png")
    except FileNotFoundError:
        diagonal = cardinal

    out = Image.new("RGBA", (len(frames) * CHAR, DIRECTIONS * CHAR))
    for row in range(DIRECTIONS):
        book = cardinal if row < CARDINAL_ROWS else diagonal
        srow = row % CARDINAL_ROWS
        # Creatures with a single-direction sheet reuse its only row.
        srow = srow if srow < book.height // CHAR else 0
        for col, frame in enumerate(frames):
            x = min(frame, book.width // CHAR - 1) * CHAR
            cell = book.crop((x, srow * CHAR, x + CHAR, (srow + 1) * CHAR))
            out.alpha_composite(cell, (col * CHAR, row * CHAR))
    return out


# Standing animations. Every actor in the packs ships these at three frames
# apiece and the game cut none of them — a standing character was frozen on
# frame 0 of their WALK, which is a mid-stride pose held forever.
#
# All four are the same geometry, and that is why they share a cell grid and a
# background-size on the client. What differs is when each is played:
#
#   idle1     the breath, looped constantly by whoever is standing
#   idle2     a gesture — a stretch, a glance — played once, occasionally
#   sleeping  a POSE: authored per NPC, replaces the breath, never wanders
#   kneel     likewise
#
# The poses are not ambient behaviour and no director picks them. A sleeping
# beggar is a decision somebody made in content/npcs/*.json, which is why they
# are opted into rather than rolled for.
POSE_FRAMES = list(range(3))
POSE_SHEETS = [
    ("idle1", "_idle"), ("idle2", "_idle2"),
    ("sleeping", "_sleeping"), ("kneel", "_kneel"),
]


FACE_CELL = 144                 # one faceset expression in the pack's grid


def cut_portrait(folder, stem, kind, size):
    """
    Faceset or bust, scaled down to a sane transfer size.

    Faces ship as a grid of expressions (Baenor's is 4x2 of 144px cells) for
    most humans but as a lone 144px cell for creatures -- take the first cell
    either way. Busts are single images, so they only need trimming.
    """
    names = {
        "face": ["Face.png"],
        "bust": ["Bust_1.png", "Bust.png"],
    }[kind]
    last = None
    for n in names:
        try:
            im = sheet(actor_file(folder, stem, n))
        except FileNotFoundError as e:
            last = e
            continue
        if kind == "face" and (im.width > FACE_CELL or im.height > FACE_CELL):
            im = im.crop((0, 0, FACE_CELL, FACE_CELL))
        else:
            im = trim(im)
        im.thumbnail((size, size), Image.LANCZOS)
        return im
    raise last


def cut_busts(folder, stem, size):
    """
    Every bust expression an actor ships, in order, neutral first.

    The Heroes pack draws each face eight times over -- neutral, angry, pleased,
    wounded and so on -- as _Bust_1 .. _Bust_8, while townfolk and monsters get a
    single _Bust. Both come back as a list so the caller does not have to know
    which kind of actor it is holding, and the count it reports is what the
    dialogue code needs to know an expression exists before asking for it.
    """
    out = []
    for i in range(1, BUST_EXPRESSIONS + 1):
        try:
            im = sheet(actor_file(folder, stem, f"Bust_{i}.png"))
        except FileNotFoundError:
            break
        im = trim(im)
        im.thumbnail((size, size), Image.LANCZOS)
        out.append(im)
    if not out:
        im = trim(sheet(actor_file(folder, stem, "Bust.png")))
        im.thumbnail((size, size), Image.LANCZOS)
        out.append(im)
    return out


def cut_battler(folder, stem):
    """Front-facing battle art, trimmed and scaled down from the pack's 1024px."""
    im = trim(sheet(actor_file(folder, stem, "Battler.png")))
    im.thumbnail((BATTLER_PX, BATTLER_PX), Image.LANCZOS)
    return im


def union_box(frames):
    """The bounding box that holds every frame, or None if they are all empty."""
    box = None
    for f in frames:
        b = f.getbbox()
        if b is None:
            continue
        box = b if box is None else (min(box[0], b[0]), min(box[1], b[1]),
                                     max(box[2], b[2]), max(box[3], b[3]))
    return box


def cut_mvsv(src):
    """
    One combat clip as a horizontal strip of MVSV_FRAMES frames.

    Frame width is read off the sheet rather than assumed: the poses are 128px
    for a goblin but 192 for an ogre and 256 for a golem, and the only thing all
    of them agree on is that three frames sit side by side.

    Like cut_animated, the frames share one bounding box -- the union of all
    three. Trimmed one by one they would each have their own size and origin, and
    a sword swing played back that way moves the fighter rather than the sword.
    """
    im = sheet(src)
    fw = im.width // MVSV_FRAMES
    frames = [im.crop((i * fw, 0, (i + 1) * fw, im.height))
              for i in range(MVSV_FRAMES)]

    box = union_box(frames)
    if box is None:
        raise ValueError(f"{src} is empty")

    w, h = box[2] - box[0], box[3] - box[1]
    strip = Image.new("RGBA", (w * MVSV_FRAMES, h))
    for i, f in enumerate(frames):
        strip.paste(f.crop(box), (i * w, 0))
    return strip, w, h


def save(im, out_dir, name):
    os.makedirs(out_dir, exist_ok=True)
    path = os.path.join(out_dir, f"{name}.png")
    im.save(path)
    return path


# The stages `--only` can name, in the order build() runs them. Cutting
# everything walks the whole pack tree, which is slow enough on a network mount
# that it cannot finish inside a short-lived shell; naming a stage is how you
# add one actor without re-reading 2.8 GB of art that has not changed.
BUILD_STAGES = {
    "ground", "autotiles", "props", "decor", "animated", "buildings",
    "classes", "scenery", "npcs", "monsters", "bosses",
    "battlers", "clips",
}


def build(verbose=True, only=None, actor_keys=None):
    stages = set(only) if only else set(BUILD_STAGES)
    keys = set(actor_keys) if actor_keys else None

    def wanted(stage):
        return stage in stages

    def pick(manifest):
        """Narrow an actor manifest to --actors, if it was given."""
        return manifest if keys is None else {
            k: v for k, v in manifest.items() if k in keys
        }

    written, failed = [], []
    # How many bust expressions each actor turned out to have, per output folder.
    # Written out as busts.json for the same reason anim/index.json exists: the
    # renderer cannot count files, and npcs.bust_count is loaded from it.
    bust_counts = {}
    combat_index = {}
    missing_clips = {}

    def attempt(label, fn):
        try:
            written.append(fn())
            if verbose:
                print(f"  ok   {label}")
        except Exception as e:  # keep going so one bad pick doesn't stall the run
            failed.append((label, str(e)))
            if verbose:
                print(f"  FAIL {label}: {e}")

    def emit_actor(name, spec, out_dir, portraits=True):
        """Walk sheet + a standing frame + portraits for one actor."""
        folder = spec[0]
        suffix = spec[1] if len(spec) > 1 else "walking"
        try:
            stem = stem_for(folder, suffix)
        except FileNotFoundError as e:
            failed.append((name, str(e)))
            if verbose:
                print(f"  FAIL {name}: {e}")
            return
        attempt(f"{name} (sheet)",
                lambda: save(cut_walk_sheet(folder, stem, suffix), out_dir, f"{name}_sheet"))
        attempt(name,
                lambda: save(cut_char(folder, stem, 0, suffix=suffix), out_dir, name))

        # The standing loop, where the actor has one. Not every source does —
        # monsters and scenery actors ship a walk and nothing else — so a
        # missing idle is silence rather than a failure: the client falls back
        # to holding the walk sheet's first frame, which is what it did for
        # everybody before this existed.
        for src_suffix, out_suffix in POSE_SHEETS:
            if not os.path.exists(os.path.join(PACK, folder, f"{stem}_{src_suffix}.png")):
                continue
            attempt(f"{name} ({src_suffix})",
                    lambda ss=src_suffix, os_=out_suffix: save(
                        cut_walk_sheet(folder, stem, ss, POSE_FRAMES),
                        out_dir, f"{name}{os_}"))
        if portraits:
            attempt(f"{name} (face)",
                    lambda: save(cut_portrait(folder, stem, "face", FACE_PX), out_dir, f"{name}_face"))

            def emit_busts():
                ims = cut_busts(folder, stem, BUST_PX)
                # Expression 1 keeps the bare <key>_bust.png name it has always
                # had, so everything already pointing at it goes on working.
                first = save(ims[0], out_dir, f"{name}_bust")
                for i, im in enumerate(ims[1:], start=2):
                    written.append(save(im, out_dir, f"{name}_bust_{i}"))
                bust_counts.setdefault(out_dir, {})[name] = len(ims)
                return first

            attempt(f"{name} (busts)", emit_busts)


    if wanted("ground"):
        print("Ground tiles:")
        for name, spec in GROUND.items():
            attempt(name, lambda n=name, s=spec: save(cut_ground(s), TILES_OUT, n))

    if wanted("autotiles"):
        print("Autotile edges:")
        for name, spec in AUTOTILES.items():
            def emit_edges(n=name, s=spec):
                first = None
                for mask, img in cut_autotile_edges(s).items():
                    path = save(img, TILES_OUT, f"{n}_e{mask}")
                    first = first or path
                return first
            attempt(f"{name} (46 edge tiles)", emit_edges)

    if wanted("props"):
        print("Props:")
        for name, spec in PROPS.items():
            attempt(name, lambda n=name, s=spec: save(cut_prop(s), TILES_OUT, n))

    if wanted("decor"):
        print("Decor:")
        for name, (src, idx) in DECOR.items():
            attempt(name, lambda n=name, s=src, i=idx: save(cut_decor(s, i), DECOR_OUT, n))

    if wanted("animated"):
        print("Animated:")
        # The renderer has to know how wide one frame is to clip the strip, and the
        # strip alone cannot say — so record it here, next to the art, rather than
        # duplicating it into every place that uses the piece.
        anim_index = {}
        for name, (src, row) in ANIMATED.items():
            def emit(n=name, s=src, r=row):
                strip, fw, fh = cut_animated(s, r)
                save(strip, ANIM_OUT, n)
                anim_index[n] = {"frames": ANIM_COLS, "w": fw, "h": fh}
            attempt(name, emit)
        if anim_index:
            os.makedirs(ANIM_OUT, exist_ok=True)
            with open(os.path.join(ANIM_OUT, "index.json"), "w") as fh:
                json.dump(dict(sorted(anim_index.items())), fh, indent=2, sort_keys=True)
            print(f"  wrote index.json ({len(anim_index)} strips)")

    if wanted("buildings"):
        print("Buildings:")
        for name, src in BUILDINGS.items():
            attempt(name, lambda n=name, s=src: save(trim(sheet(s)), BUILDINGS_OUT, n))

    if wanted("classes"):
        print("Class sprites:")
        for name, spec in pick(CLASS_SPRITES).items():
            emit_actor(name, spec, NPCS_OUT)

    if wanted("scenery"):
        print("Scenery NPCs:")
        for name, spec in SCENERY_NPCS.items():
            attempt(name, lambda n=name, s=spec: save(cut_prop(s), NPCS_OUT, n))

    if wanted("npcs"):
        print("NPCs:")
        for name, spec in pick(NPCS).items():
            emit_actor(name, spec, NPCS_OUT)

    if wanted("monsters"):
        print("Monsters:")
        for name, spec in pick(MONSTERS).items():
            emit_actor(name, spec, MONSTERS_OUT)

        # Bosses share the monsters' output directory: a sprite_key resolves to one
        # folder, and splitting them would mean the renderer had to know which kind
        # of thing it was drawing before it could find the picture.
    if wanted("bosses"):
        print("Bosses:")
        for name, spec in pick(BOSS_SPRITES).items():
            emit_actor(name, spec, MONSTERS_OUT)

    for out_dir, counts in bust_counts.items():
        os.makedirs(out_dir, exist_ok=True)
        path = os.path.join(out_dir, "busts.json")
        # Merged with what is already recorded, not replaced. A full run rewrites
        # every actor anyway, but a `--only bosses` run knows about one — and
        # replacing the file would tell the loader that the other twenty-four
        # actors have no busts, which fails validation on content that was fine
        # a moment earlier.
        merged = {}
        if os.path.exists(path):
            try:
                with open(path) as fh:
                    existing = json.load(fh)
                if isinstance(existing, dict):
                    merged.update(existing)
            except (OSError, ValueError):
                pass    # unreadable is the same as absent; this run rebuilds it
        merged.update(counts)
        with open(path, "w") as fh:
            json.dump(dict(sorted(merged.items())), fh, indent=2, sort_keys=True)
        print(f"  wrote {os.path.basename(out_dir)}/busts.json ({len(counts)} actors)")

    if wanted("battlers"):
        print("Battlers:")
        for name, spec in pick(BATTLERS).items():
            def emit(n=name, s=spec):
                folder = s[0]
                stem = stem_for(folder, s[1] if len(s) > 1 else "walking")
                return save(cut_battler(folder, stem), BATTLERS_OUT, n)
            attempt(name, emit)

    if wanted("clips"):
        print("Combat clips:")
        for name, spec in pick(MVSV_ACTORS).items():
            folder = spec[0]
            try:
                stem = stem_for(folder, spec[1] if len(spec) > 1 else "walking")
            except FileNotFoundError as e:
                failed.append((name, str(e)))
                continue
            for clip in MVSV:
                try:
                    src = actor_file(folder, stem, f"MVsv_alt_{clip}.png")
                except FileNotFoundError:
                    # Plenty of actors are simply not drawn doing every pose -- the
                    # beasts have no side-view art at all. Note it and move on.
                    missing_clips.setdefault(name, []).append(clip)
                    continue

                def emit(n=name, c=clip, s=src):
                    strip, w, h = cut_mvsv(s)
                    combat_index[f"{n}_{c}"] = {"frames": MVSV_FRAMES, "w": w, "h": h}
                    return save(strip, COMBAT_OUT, f"{n}_{c}")

                attempt(f"{name} {clip}", emit)

        if combat_index:
            os.makedirs(COMBAT_OUT, exist_ok=True)
            with open(os.path.join(COMBAT_OUT, "index.json"), "w") as fh:
                json.dump(dict(sorted(combat_index.items())), fh, indent=2, sort_keys=True)
            print(f"  wrote index.json ({len(combat_index)} strips)")

        print(f"\n{len(written)} written, {len(failed)} failed")
        for label, err in failed:
            print(f"  {label}: {err}")
        if missing_clips:
            print("\nno source art for these combat clips:")
            for name in sorted(missing_clips):
                clips = missing_clips[name]
                what = "all" if len(clips) == len(MVSV) else ", ".join(clips)
                print(f"  {name}: {what}")
    return written, failed


def catalog_boxes(rel, scale=4, min_px=20, max_px=420):
    """
    Bounding boxes of the individual props on an object sheet, in reading order.

    The B-E sheets are loose objects separated by transparent gutters, so a
    connected-component pass over the alpha channel recovers each one's box
    without anybody hand-measuring pixel coordinates. DECOR refers to pieces by
    their position in this list.
    """
    im = sheet(rel)
    a = im.getchannel("A").resize((im.width // scale, im.height // scale), Image.BOX)
    w, h = a.size
    px = a.load()

    # Union-find over the thresholded mask, 8-connected.
    parent = {}

    def find(i):
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    def union(i, j):
        ri, rj = find(i), find(j)
        if ri != rj:
            parent[max(ri, rj)] = min(ri, rj)

    for y in range(h):
        for x in range(w):
            if px[x, y] <= 16:
                continue
            i = y * w + x
            parent.setdefault(i, i)
            for dx, dy in ((-1, 0), (-1, -1), (0, -1), (1, -1)):
                nx, ny = x + dx, y + dy
                if 0 <= nx < w and 0 <= ny < h and px[nx, ny] > 16:
                    j = ny * w + nx
                    parent.setdefault(j, j)
                    union(i, j)

    boxes = {}
    for i in parent:
        r = find(i)
        x, y = i % w, i // w
        b = boxes.get(r)
        boxes[r] = (x, y, x, y) if b is None else (min(b[0], x), min(b[1], y), max(b[2], x), max(b[3], y))

    found = []
    for x0, y0, x1, y1 in boxes.values():
        bx, by = x0 * scale, y0 * scale
        bw, bh = (x1 - x0 + 1) * scale, (y1 - y0 + 1) * scale
        if min(bw, bh) < min_px or max(bw, bh) > max_px:
            continue
        found.append((bx, by, bw, bh))
    found.sort(key=lambda b: (b[1], b[0]))
    return found


def catalog(rel, out_path):
    """Print catalog_boxes() and write a numbered contact sheet to pick from."""
    im = sheet(rel)
    found = catalog_boxes(rel)

    cell, cols = 150, 12
    rows = (len(found) + cols - 1) // cols
    board = Image.new("RGBA", (cols * cell, max(rows, 1) * cell), (26, 26, 34, 255))
    d = ImageDraw.Draw(board)
    for i, (bx, by, bw, bh) in enumerate(found):
        piece = im.crop((bx, by, bx + bw, by + bh))
        piece.thumbnail((cell - 12, cell - 26))
        cx, cy = (i % cols) * cell, (i // cols) * cell
        board.alpha_composite(piece, (cx + (cell - piece.width) // 2, cy + 20))
        d.text((cx + 3, cy + 4), f"{i}: {bw}x{bh}", fill=(255, 220, 90, 255))
        d.rectangle([cx, cy, cx + cell - 1, cy + cell - 1], outline=(70, 70, 88, 255))
    board.convert("RGB").save(out_path)

    print(f"# {rel} -> {len(found)} pieces, contact sheet: {out_path}")
    for i, b in enumerate(found):
        print(f"#  {i}: {b}")
    return found


def preview(path):
    """Contact sheet of everything we cut, for eyeballing the picks."""
    groups = [
        ("ground", TILES_OUT, list(GROUND)),
        ("props", TILES_OUT, list(PROPS)),
        ("decor", DECOR_OUT, list(DECOR)),
        ("classes", NPCS_OUT, list(CLASS_SPRITES)),
        ("class faces", NPCS_OUT, [f"{n}_face" for n in CLASS_SPRITES]),
        ("npcs", NPCS_OUT, list(NPCS) + list(SCENERY_NPCS)),
        ("monsters", MONSTERS_OUT, list(MONSTERS) + list(BOSS_SPRITES)),
        ("monster faces", MONSTERS_OUT,
            [f"{n}_face" for n in list(MONSTERS) + list(BOSS_SPRITES)]),
        ("battlers", BATTLERS_OUT, list(BATTLERS)),
        # One clip per actor is enough to see the crop landed; the whole set is
        # six times as many tiles and tells you nothing more.
        ("combat (attack1)", COMBAT_OUT, [f"{n}_attack1" for n in MVSV_ACTORS]),
    ]
    cell, cols = 160, 10
    rows = sum((len(names) + cols - 1) // cols for _, _, names in groups)
    board = Image.new("RGBA", (cols * cell, rows * cell + len(groups) * 26), (26, 26, 34, 255))
    d = ImageDraw.Draw(board)
    y = 0
    for title, folder, names in groups:
        d.text((6, y + 6), title.upper(), fill=(255, 210, 90, 255))
        y += 26
        for i, name in enumerate(names):
            p = os.path.join(folder, f"{name}.png")
            if not os.path.exists(p):
                continue
            im = Image.open(p).convert("RGBA")
            im.thumbnail((cell - 12, cell - 26))
            cx, cy = (i % cols) * cell, y + (i // cols) * cell
            # checker so transparent edges are visible
            for sx in range(cx, cx + cell, 12):
                for sy in range(cy + 18, cy + cell, 12):
                    if ((sx // 12) + (sy // 12)) % 2:
                        d.rectangle([sx, sy, sx + 11, sy + 11], fill=(44, 44, 54, 255))
            board.alpha_composite(im, (cx + 6, cy + 20))
            d.text((cx + 4, cy + 4), name, fill=(200, 220, 255, 255))
            d.rectangle([cx, cy, cx + cell - 1, cy + cell - 1], outline=(70, 70, 88, 255))
        y += ((len(names) + cols - 1) // cols) * cell
    board.convert("RGB").save(path)
    print(f"preview -> {path}")


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--preview", metavar="PATH", nargs="?", const="preview.png",
                    help="also write a contact sheet of every piece cut")
    ap.add_argument("--catalog", metavar="SHEET",
                    help="index the loose props on one object sheet (path relative to the pack) "
                         "and print their boxes instead of building anything")
    ap.add_argument("--catalog-out", metavar="PATH", default="catalog.png",
                    help="where --catalog writes its numbered contact sheet")
    # Cutting everything reads the whole 2.8 GB pack tree, which takes long
    # enough that it cannot run inside a short-lived shell. Naming a stage cuts
    # only that, so adding one actor does not mean re-cutting 165 decor pieces
    # whose source has not changed.
    ap.add_argument("--only", metavar="STAGE", action="append", default=None,
                    choices=sorted(BUILD_STAGES),
                    help="cut only these stages (repeatable): "
                         + ", ".join(sorted(BUILD_STAGES)))
    ap.add_argument("--actors", metavar="KEY", action="append", default=None,
                    help="within the actor stages, cut only these keys (repeatable)")
    ap.add_argument("--list-stages", action="store_true",
                    help="print the stage names and what each one cuts")
    args = ap.parse_args()

    if args.list_stages:
        for name in sorted(BUILD_STAGES):
            print(f"  {name}")
        sys.exit(0)

    if not os.path.isdir(PACK):
        sys.exit(f"pack tree not found: {PACK}\nSet RPG_PACK_DIR to point at it.")

    if args.catalog:
        catalog(args.catalog, args.catalog_out)
        sys.exit(0)

    build(only=args.only, actor_keys=args.actors)
    if args.preview:
        preview(args.preview)
