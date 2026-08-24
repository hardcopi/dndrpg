<?php
/**
 * The room you are standing in, as the ground a fight happens on.
 *
 * Everywhere else the battlefield is invented: BattleMapGen draws sixteen by
 * twelve cells of plausible terrain from a palette, because a hundred-odd
 * locations exist and none of them have a floor plan. A delve is the exception.
 * Its rooms ARE drawn — the chart the party is reading is the level's real
 * shape — so inventing a battlefield for a fight inside one throws that away
 * and puts the party somewhere that looks nothing like where they were
 * standing a moment ago.
 *
 * So: the room becomes the board. Its footprint sets the size, its walls sit
 * on the rim, and the passages that meet it are gaps in that rim rather than
 * decoration. A room drawn thirteen by nine on the plan is fought on a board
 * of the same proportions, and the doorway the party walked in through is the
 * doorway behind them.
 *
 * SCALE. The plan is ruled in ten-foot tiles; the battle grid is five feet a
 * cell (BattleGrid::CELL_FT). So one tile is two cells each way, and a room of
 * 13x9 tiles is a floor of 26x18 cells inside a rim — bigger than a generated
 * board, deliberately. Big rooms play big.
 *
 * Everything here is derived from the stored level, so a fight in a room is
 * the same board every time it is fought, without a seed being involved at
 * all: the room is not random, it was drawn when the floor was.
 */

declare(strict_types=1);

final class RoomBattleMap
{
    /** Cells per ten-foot plan tile. Ten feet over five feet a cell. */
    private const CELLS_PER_TILE = 2;

    /** How deep a deployment zone runs from its wall, in cells. */
    private const ZONE_DEPTH = 3;

    /**
     * The board for a delve room, or null if this location is not one.
     *
     * Null is not a failure — it is the answer for every location that is not
     * a room on a generated floor, which is most of them. The caller falls
     * back to the generated board exactly as it did before.
     *
     * @return array{terrain: string[], zones: array{party: int[], foe: int[]}}|null
     */
    public static function forLocation(PDO $db, string $locationKey): ?array
    {
        $room = self::locate($db, $locationKey);
        if ($room === null) {
            return null;
        }
        return self::build($room, $room['doors']);
    }

    /**
     * The room's footprint in TILES, and the walls its passages come through.
     *
     * The location key carries everything needed to find it — party, depth and
     * room index, see DelveEngine::roomKey — so no state has to be threaded
     * through the combat engine to get here.
     *
     * Tiles, not cells: a room's `w` is measured in the level's own grid
     * cells, and on a floor from this generator one of those is `sub` tiles.
     * The ten-foot tile is the unit the plan is ruled in and the one the board
     * is built from, so the conversion happens once, here.
     *
     * @return array{w:int, h:int, doors: list<array{side:string,at:int}>}|null
     */
    private static function locate(PDO $db, string $locationKey): ?array
    {
        // _dg_{party}_{depth}_r{room}. A passage is `c` and is not a room; a
        // fight in a corridor keeps the generated board, which is the right
        // answer for a space two cells wide.
        if (!preg_match('/^_dg_(\d+)_(\d+)_r(\d+)$/', $locationKey, $m)) {
            return null;
        }
        [, $partyId, $depth, $roomId] = $m;

        $stmt = $db->prepare(
            'SELECT level_json FROM dungeon_delves WHERE party_id = ? AND depth = ?'
        );
        $stmt->execute([(int) $partyId, (int) $depth]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || $json === '') {
            return null;
        }
        $level = json_decode($json, true);
        if (!is_array($level) || empty($level['rooms'])) {
            return null;
        }

        $room = null;
        foreach ($level['rooms'] as $r) {
            if ((int) ($r['id'] ?? -1) === (int) $roomId) {
                $room = $r;
                break;
            }
        }
        if ($room === null) {
            return null;
        }

        [$gw, $gh, $sub] = DungeonGen::gridOf($level);
        $tw = $sub * $gw + 2;

        // The room's tile rectangle, by the same arithmetic roomTiles() uses.
        $x0 = $sub * (int) $room['gx'] + 1;
        $x1 = $sub * ((int) $room['gx'] + (int) $room['w']);
        $y0 = $sub * (int) $room['gy'] + 1;
        $y1 = $sub * ((int) $room['gy'] + (int) $room['h']);
        if ($x1 < $x0 || $y1 < $y0) {
            return null;
        }

        return [
            'w'     => $x1 - $x0 + 1,
            'h'     => $y1 - $y0 + 1,
            'doors' => self::doorsOf($level, (int) $roomId, $tw, $x0, $x1, $y0, $y1),
        ];
    }

    /**
     * Which wall each passage comes through, and how far along it.
     *
     * The runs are not stored with the level — only the graph is, and the
     * routing is recomputed from it — so this asks DungeonGen::routes() for
     * them, which is the same call plan() makes to draw the chart. What comes
     * back for each passage is a `from` and a `to`: the tile INSIDE each room
     * that the passage meets, which is the doorway itself.
     *
     * @return list<array{side:string,at:int}>
     */
    private static function doorsOf(
        array $level,
        int $roomId,
        int $tw,
        int $x0,
        int $x1,
        int $y0,
        int $y1
    ): array {
        $doors = [];
        foreach (DungeonGen::routes($level) as $id => $r) {
            $edge = null;
            foreach ($level['corridors'] ?? [] as $c) {
                if ((int) ($c['id'] ?? -1) === (int) $id) {
                    $edge = $c;
                    break;
                }
            }
            if ($edge === null) {
                continue;
            }
            foreach ([['a', 'from'], ['b', 'to']] as [$end, $key]) {
                if ((int) ($edge[$end] ?? -1) !== $roomId || !isset($r[$key])) {
                    continue;
                }
                $tile = (int) $r[$key];
                $tx = $tile % $tw;
                $ty = intdiv($tile, $tw);
                if ($tx < $x0 || $tx > $x1 || $ty < $y0 || $ty > $y1) {
                    continue;
                }
                // On the rectangle's edge, which is where a doorway is. A tile
                // in a corner satisfies two of these; the first wins, and a
                // door in a corner is drawn on one of its two walls rather
                // than on both.
                if ($tx === $x0)      { $doors[] = ['side' => 'w', 'at' => $ty - $y0]; }
                elseif ($tx === $x1)  { $doors[] = ['side' => 'e', 'at' => $ty - $y0]; }
                elseif ($ty === $y0)  { $doors[] = ['side' => 'n', 'at' => $tx - $x0]; }
                elseif ($ty === $y1)  { $doors[] = ['side' => 's', 'at' => $tx - $x0]; }
            }
        }
        return $doors;
    }

    /**
     * The board: a floor the size of the room, inside a wall.
     *
     * The rim is one cell of wall on every side, which is what gives the room
     * an edge to be drawn and fought against. It costs two cells in each
     * dimension over the floor itself, so the numbers quoted elsewhere — a
     * 13x9 room is 26x18 — are the FLOOR; the board is 28x20.
     *
     * @param array{w:int,h:int} $room
     * @param list<array{side:string,at:int}> $doors
     * @return array{terrain: string[], zones: array{party: int[], foe: int[]}}
     */
    private static function build(array $room, array $doors): array
    {
        $fw = $room['w'] * self::CELLS_PER_TILE;
        $fh = $room['h'] * self::CELLS_PER_TILE;
        $w  = $fw + 2;
        $h  = $fh + 2;

        $terrain = [];
        for ($y = 0; $y < $h; $y++) {
            $onRim = $y === 0 || $y === $h - 1;
            $terrain[$y] = $onRim ? str_repeat('#', $w) : '#' . str_repeat('.', $fw) . '#';
        }

        // The doorways, cut out of the rim. ONE cell, which at
        // BattleGrid::CELL_FT is five feet — a door.
        //
        // It was two, on the reasoning that a party wants to walk through
        // rather than file through. That is a reason to want a wide opening,
        // not a reason for a door to be one: a tile is ten feet, and a
        // ten-foot door is a gateway. Single file through a doorway is the
        // correct tactical problem, and it is the one the room was drawn with.
        //
        // The tile's leading cell, consistently, so a door is in the same
        // place every time the same room is fought in.
        foreach ($doors as $d) {
            $off = $d['at'] * self::CELLS_PER_TILE + 1;
            if ($d['side'] === 'w' || $d['side'] === 'e') {
                if ($off < 1 || $off > $fh) { continue; }
                $x = $d['side'] === 'w' ? 0 : $w - 1;
                $terrain[$off] = substr_replace($terrain[$off], '.', $x, 1);
            } else {
                if ($off < 1 || $off > $fw) { continue; }
                $y = $d['side'] === 'n' ? 0 : $h - 1;
                $terrain[$y] = substr_replace($terrain[$y], '.', $off, 1);
            }
        }

        return ['terrain' => $terrain, 'zones' => self::zones($fw, $fh, $doors)];
    }

    /**
     * Where each side starts.
     *
     * The party comes in through a door, so they deploy against the wall that
     * has one and the monsters get the far end. With doors on more than one
     * wall the busiest is chosen; with none — which a sealed room can be —
     * the room's long axis decides, so the two sides are as far apart as the
     * room allows rather than shoulder to shoulder.
     *
     * @param list<array{side:string,at:int}> $doors
     * @return array{party: int[], foe: int[]}
     */
    private static function zones(int $fw, int $fh, array $doors): array
    {
        $count = ['w' => 0, 'e' => 0, 'n' => 0, 's' => 0];
        foreach ($doors as $d) {
            $count[$d['side']] = ($count[$d['side']] ?? 0) + 1;
        }
        arsort($count);
        $side = (string) array_key_first($count);
        if ($count[$side] === 0) {
            $side = $fw >= $fh ? 'w' : 'n';
        }

        // Never deeper than half the room, or on a small floor the two zones
        // would meet in the middle and the fight would start already joined.
        $deepX = (int) min(self::ZONE_DEPTH, max(1, intdiv($fw, 2)));
        $deepY = (int) min(self::ZONE_DEPTH, max(1, intdiv($fh, 2)));

        // Inclusive cell coordinates, and the floor starts at 1 because of the
        // rim — the same shape BattleMapGen's zones are in.
        switch ($side) {
            case 'e':
                return [
                    'party' => [$fw + 1 - $deepX, 1, $fw, $fh],
                    'foe'   => [1, 1, $deepX, $fh],
                ];
            case 'n':
                return [
                    'party' => [1, 1, $fw, $deepY],
                    'foe'   => [1, $fh + 1 - $deepY, $fw, $fh],
                ];
            case 's':
                return [
                    'party' => [1, $fh + 1 - $deepY, $fw, $fh],
                    'foe'   => [1, 1, $fw, $deepY],
                ];
            default:
                return [
                    'party' => [1, 1, $deepX, $fh],
                    'foe'   => [$fw + 1 - $deepX, 1, $fw, $fh],
                ];
        }
    }
}
