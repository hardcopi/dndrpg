<?php
/**
 * The ground a fight happens on.
 *
 * A hundred-odd locations exist and none of them have an authored battlefield,
 * so the map is generated: a seed and a palette in, sixteen by twelve cells of
 * terrain and two deployment zones out. The palette comes from the location's
 * own type and the type of the region holding it, which is how a brawl in the
 * Drowned Flagon gets tables and a fight in the Old City gets stalagmites
 * without anybody drawing either.
 *
 * The seed is derived from the encounter, so a given fight always looks the
 * same — walk back into the goblin ambush and the boulder is where you left it.
 * That is worth more than variety here: a battlefield that reshuffles between
 * attempts makes a fight impossible to learn.
 *
 * Pure static and free of the database, so `tools/test_combat.php` can generate
 * two hundred maps per palette and assert every one of them is playable.
 */

declare(strict_types=1);

final class BattleMapGen
{
    /**
     * Deployment zones: three columns a side, inset one cell from the rim, as
     * [x0, y0, x1, y1] inclusive.
     *
     * Nothing is ever stamped inside a zone. That single rule is most of the
     * traversability guarantee — spawn cells cannot be walled off because
     * nothing is ever built on them.
     */
    public const ZONE_PARTY = [1, 1, 3, BattleGrid::H - 2];
    public const ZONE_FOE   = [BattleGrid::W - 4, 1, BattleGrid::W - 2, BattleGrid::H - 2];

    /**
     * What each palette builds.
     *
     * `rim` walls the outside edge — right for a room or a tunnel, wrong for a
     * street or a clearing, where the fight simply carries on off the board.
     * Features are stamped as rectangles of a terrain character, `min` to `max`
     * of each, only in the corridor between the two zones.
     */
    private const PALETTES = [
        'arena' => [
            'rim'      => false,
            'features' => [
                ['char' => ',', 'w' => 3, 'h' => 2, 'min' => 1, 'max' => 3],
                ['char' => 'o', 'w' => 1, 'h' => 3, 'min' => 2, 'max' => 2],
            ],
        ],
        'interior' => [
            'rim'      => true,
            'features' => [
                ['char' => 'o', 'w' => 2, 'h' => 1, 'min' => 2, 'max' => 4],
                ['char' => 'o', 'w' => 1, 'h' => 3, 'min' => 0, 'max' => 1],
                ['char' => 'n', 'w' => 2, 'h' => 2, 'min' => 1, 'max' => 1],
                ['char' => ',', 'w' => 2, 'h' => 1, 'min' => 0, 'max' => 2],
            ],
        ],
        'street' => [
            'rim'      => false,
            'features' => [
                ['char' => 'o', 'w' => 2, 'h' => 1, 'min' => 1, 'max' => 3],
                ['char' => 'o', 'w' => 1, 'h' => 1, 'min' => 2, 'max' => 4],
                ['char' => 'n', 'w' => 1, 'h' => 1, 'min' => 0, 'max' => 1],
                ['char' => ',', 'w' => 1, 'h' => 2, 'min' => 0, 'max' => 2],
            ],
        ],
        'cavern' => [
            'rim'      => true,
            'features' => [
                ['char' => 'n', 'w' => 1, 'h' => 1, 'min' => 3, 'max' => 6],
                ['char' => ',', 'w' => 2, 'h' => 2, 'min' => 1, 'max' => 3],
                ['char' => 'v', 'w' => 1, 'h' => 2, 'min' => 0, 'max' => 2],
            ],
        ],
        'tunnel' => [
            'rim'      => true,
            'pinch'    => true,
            'features' => [
                ['char' => ',', 'w' => 2, 'h' => 1, 'min' => 1, 'max' => 3],
                ['char' => 'n', 'w' => 1, 'h' => 1, 'min' => 1, 'max' => 2],
            ],
        ],
        'camp' => [
            'rim'      => false,
            'features' => [
                ['char' => 'n', 'w' => 1, 'h' => 1, 'min' => 1, 'max' => 1],
                ['char' => 'o', 'w' => 2, 'h' => 2, 'min' => 1, 'max' => 2],
                ['char' => 'o', 'w' => 2, 'h' => 1, 'min' => 1, 'max' => 2],
                ['char' => ',', 'w' => 2, 'h' => 1, 'min' => 0, 'max' => 2],
            ],
        ],
        'forest' => [
            'rim'      => false,
            'features' => [
                ['char' => 'n', 'w' => 1, 'h' => 1, 'min' => 3, 'max' => 6],
                ['char' => 'o', 'w' => 1, 'h' => 1, 'min' => 1, 'max' => 3],
                ['char' => ',', 'w' => 2, 'h' => 2, 'min' => 1, 'max' => 2],
                ['char' => '~', 'w' => 1, 'h' => 4, 'min' => 0, 'max' => 1],
            ],
        ],
    ];

    /**
     * Which palette a location fights on.
     *
     * The value sets are small and closed: `location_type` is one of room,
     * site, building, street, gate, camp, square or arena, and `region_type` is
     * town, wilderness or dungeon. An unknown pairing falls through to street,
     * which is open ground with a little clutter and is wrong in the least
     * interesting way.
     */
    public static function paletteFor(string $locationType, string $regionType): string
    {
        $locationType = strtolower(trim($locationType));
        $regionType = strtolower(trim($regionType));

        if ($locationType === 'arena') {
            return 'arena';
        }
        if ($regionType === 'dungeon') {
            return in_array($locationType, ['room', 'building'], true) ? 'cavern' : 'tunnel';
        }
        if ($regionType === 'wilderness') {
            return $locationType === 'camp' ? 'camp' : 'forest';
        }
        // town, and anything that did not match
        return in_array($locationType, ['building', 'room'], true) ? 'interior' : 'street';
    }

    /**
     * Build a battlefield.
     *
     * @return array{w:int,h:int,cell_ft:int,seed:int,palette:string,terrain:string[],zones:array}
     */
    public static function generate(int $seed, string $palette): array
    {
        if (!isset(self::PALETTES[$palette])) {
            $palette = 'street';
        }
        $spec = self::PALETTES[$palette];
        $rng = $seed & 0xFFFFFFFF;

        $grid = self::blank();
        if (!empty($spec['rim'])) {
            self::stampRim($grid);
        }
        if (!empty($spec['pinch'])) {
            self::stampPinch($grid, $rng);
        }
        foreach ($spec['features'] as $feature) {
            $n = self::rint($rng, (int) $feature['min'], (int) $feature['max']);
            for ($i = 0; $i < $n; $i++) {
                self::stampFeature($grid, $rng, (string) $feature['char'], (int) $feature['w'], (int) $feature['h']);
            }
        }

        $terrain = self::rows($grid);
        if (!self::connected($terrain)) {
            self::carve($grid);
            $terrain = self::rows($grid);
        }
        if (!self::connected($terrain)) {
            // A generator that can fail needs a boring answer rather than an
            // exception: this runs inside startEncounter(), and throwing here
            // loses the fight rather than the map.
            $grid = self::blank();
            if (!empty($spec['rim'])) {
                self::stampRim($grid);
            }
            $terrain = self::rows($grid);
        }

        return [
            'w'       => BattleGrid::W,
            'h'       => BattleGrid::H,
            'cell_ft' => BattleGrid::CELL_FT,
            'seed'    => $seed,
            'palette' => $palette,
            'terrain' => $terrain,
            'zones'   => ['party' => self::ZONE_PARTY, 'foe' => self::ZONE_FOE],
        ];
    }

    /**
     * Where each combatant starts.
     *
     * `$prefs` is the old front/back rank, one entry per combatant, in the
     * order they should be placed. It no longer decides anything about reach —
     * it decides which end of the deployment zone somebody stands at, which is
     * the honest remainder of what "I fight from the back" ever meant.
     *
     * Rows fill from the middle of the zone outward so that four people cluster
     * around the centre line rather than lining up along a wall, and so that a
     * party of two does not start in opposite corners.
     *
     * @param string[] $prefs 'front' or 'back', one per combatant
     * @param string   $facing 'right' if the enemy is at higher x
     * @return array<int, array{x:int,y:int}> in the same order as $prefs
     */
    public static function deploy(array $terrain, array $zone, array $prefs, string $facing): array
    {
        [$x0, $y0, $x1, $y1] = $zone;
        $cols = range($x0, $x1);
        $frontCols = $facing === 'right' ? array_reverse($cols) : $cols;
        $backCols = array_reverse($frontCols);

        $rows = range($y0, $y1);
        $mid = ($y0 + $y1) / 2;
        usort($rows, static function (int $a, int $b) use ($mid): int {
            $da = abs($a - $mid);
            $db = abs($b - $mid);
            return $da === $db ? $a <=> $b : ($da < $db ? -1 : 1);
        });

        $taken = [];
        $out = [];
        foreach ($prefs as $i => $pref) {
            $order = $pref === 'back' ? $backCols : $frontCols;
            $placed = null;
            foreach ($order as $x) {
                foreach ($rows as $y) {
                    $key = BattleGrid::keyOf($x, $y);
                    if (isset($taken[$key]) || !BattleGrid::passable($terrain, $x, $y)) {
                        continue;
                    }
                    $placed = ['x' => $x, 'y' => $y];
                    break 2;
                }
            }
            if ($placed === null) {
                $placed = self::anywhere($terrain, $taken, $zone);
            }
            $taken[BattleGrid::keyOf($placed['x'], $placed['y'])] = true;
            $out[$i] = $placed;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Building blocks
    // -----------------------------------------------------------------------

    /** @return array<int, array<int, string>> */
    private static function blank(): array
    {
        $grid = [];
        for ($y = 0; $y < BattleGrid::H; $y++) {
            $grid[$y] = array_fill(0, BattleGrid::W, '.');
        }
        return $grid;
    }

    /** @return string[] */
    private static function rows(array $grid): array
    {
        $out = [];
        foreach ($grid as $row) {
            $out[] = implode('', $row);
        }
        return $out;
    }

    private static function stampRim(array &$grid): void
    {
        for ($x = 0; $x < BattleGrid::W; $x++) {
            $grid[0][$x] = '#';
            $grid[BattleGrid::H - 1][$x] = '#';
        }
        for ($y = 0; $y < BattleGrid::H; $y++) {
            $grid[$y][0] = '#';
            $grid[$y][BattleGrid::W - 1] = '#';
        }
    }

    /**
     * A tunnel narrows in the middle: walls creep in from the top and bottom of
     * the corridor between the zones, so the fight is fought through a gap.
     */
    private static function stampPinch(array &$grid, int &$rng): void
    {
        [$cx0, $cx1] = self::corridor();
        for ($x = $cx0; $x <= $cx1; $x++) {
            $top = self::rint($rng, 1, 3);
            $bottom = self::rint($rng, 1, 3);
            for ($i = 1; $i <= $top; $i++) {
                $grid[$i][$x] = '#';
            }
            for ($i = 1; $i <= $bottom; $i++) {
                $grid[BattleGrid::H - 1 - $i][$x] = '#';
            }
        }
    }

    /**
     * Drop one rectangle of terrain somewhere in the corridor.
     *
     * Tries a handful of positions and gives up quietly rather than looping: a
     * crowded map with one fewer crate is not worth a stall inside a request.
     */
    private static function stampFeature(array &$grid, int &$rng, string $char, int $w, int $h): void
    {
        [$cx0, $cx1] = self::corridor();
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $x = self::rint($rng, $cx0, max($cx0, $cx1 - $w + 1));
            $y = self::rint($rng, 1, max(1, BattleGrid::H - 1 - $h));
            if (!self::clearFor($grid, $x, $y, $w, $h)) {
                continue;
            }
            for ($j = 0; $j < $h; $j++) {
                for ($i = 0; $i < $w; $i++) {
                    $grid[$y + $j][$x + $i] = $char;
                }
            }
            return;
        }
    }

    /** Open ground, on the board, and clear of both deployment zones. */
    private static function clearFor(array $grid, int $x, int $y, int $w, int $h): bool
    {
        for ($j = 0; $j < $h; $j++) {
            for ($i = 0; $i < $w; $i++) {
                $px = $x + $i;
                $py = $y + $j;
                if (!BattleGrid::inBounds($px, $py) || $grid[$py][$px] !== '.') {
                    return false;
                }
                if (self::inZone($px, $py, self::ZONE_PARTY) || self::inZone($px, $py, self::ZONE_FOE)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** The columns between the two deployment zones, with a cell of air either side. */
    private static function corridor(): array
    {
        return [self::ZONE_PARTY[2] + 1, self::ZONE_FOE[0] - 1];
    }

    public static function inZone(int $x, int $y, array $zone): bool
    {
        return $x >= $zone[0] && $x <= $zone[2] && $y >= $zone[1] && $y <= $zone[3];
    }

    // -----------------------------------------------------------------------
    // The traversability guarantee
    // -----------------------------------------------------------------------

    /**
     * Can the party zone reach the foe zone on foot?
     *
     * Flood filled orthogonally, which is stricter than the eight-way movement
     * BattleGrid::reachable() actually allows. Deliberately: a map that passes
     * this is certainly walkable, where one that only passed diagonally might
     * be threading a corner the movement rule refuses to cut.
     */
    private static function connected(array $terrain): bool
    {
        $seen = [];
        $queue = [];
        for ($y = self::ZONE_PARTY[1]; $y <= self::ZONE_PARTY[3]; $y++) {
            for ($x = self::ZONE_PARTY[0]; $x <= self::ZONE_PARTY[2]; $x++) {
                if (BattleGrid::passable($terrain, $x, $y)) {
                    $seen[BattleGrid::keyOf($x, $y)] = true;
                    $queue[] = [$x, $y];
                }
            }
        }
        if (!$queue) {
            return false;
        }

        while ($queue) {
            [$cx, $cy] = array_pop($queue);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $cx + $dx;
                $ny = $cy + $dy;
                $key = BattleGrid::keyOf($nx, $ny);
                if (isset($seen[$key]) || !BattleGrid::inBounds($nx, $ny)) {
                    continue;
                }
                if (!BattleGrid::passable($terrain, $nx, $ny)) {
                    continue;
                }
                $seen[$key] = true;
                $queue[] = [$nx, $ny];
            }
        }

        for ($y = self::ZONE_FOE[1]; $y <= self::ZONE_FOE[3]; $y++) {
            for ($x = self::ZONE_FOE[0]; $x <= self::ZONE_FOE[2]; $x++) {
                if (BattleGrid::passable($terrain, $x, $y) && !isset($seen[BattleGrid::keyOf($x, $y)])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Open a road between the two zones.
     *
     * Clears every impassable cell on the straight line between the zone
     * centres. Both zones are clear by construction, so a cleared line always
     * joins them — this cannot fail to work, which is why there is no loop.
     */
    private static function carve(array &$grid): void
    {
        $ax = self::ZONE_PARTY[2];
        $ay = intdiv(self::ZONE_PARTY[1] + self::ZONE_PARTY[3], 2);
        $bx = self::ZONE_FOE[0];
        $by = intdiv(self::ZONE_FOE[1] + self::ZONE_FOE[3], 2);

        $steps = max(abs($bx - $ax), abs($by - $ay));
        for ($i = 0; $i <= $steps; $i++) {
            $x = (int) round($ax + ($bx - $ax) * $i / max(1, $steps));
            $y = (int) round($ay + ($by - $ay) * $i / max(1, $steps));
            if (BattleGrid::inBounds($x, $y) && (BattleGrid::TERRAIN[$grid[$y][$x]]['cost'] ?? null) === null) {
                $grid[$y][$x] = '.';
            }
        }
    }

    /** Last resort for deployment: the nearest free cell to the zone's centre. */
    private static function anywhere(array $terrain, array $taken, array $zone): array
    {
        $cx = intdiv($zone[0] + $zone[2], 2);
        $cy = intdiv($zone[1] + $zone[3], 2);
        $best = null;
        $bestD = PHP_INT_MAX;
        for ($y = 0; $y < BattleGrid::H; $y++) {
            for ($x = 0; $x < BattleGrid::W; $x++) {
                $key = BattleGrid::keyOf($x, $y);
                if (isset($taken[$key]) || !BattleGrid::passable($terrain, $x, $y)) {
                    continue;
                }
                $d = BattleGrid::cells($cx, $cy, $x, $y);
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = ['x' => $x, 'y' => $y];
                }
            }
        }
        return $best ?? ['x' => $cx, 'y' => $cy];
    }

    // -----------------------------------------------------------------------
    // Randomness
    // -----------------------------------------------------------------------

    /**
     * splitmix32, kept local on purpose.
     *
     * `mt_srand()` would be shorter and is the wrong tool: it seeds a global,
     * so generating a map inside a request perturbs every other random draw in
     * that request — including the dice. This carries its state in an argument
     * and touches nothing.
     *
     * Changing this function re-rolls every battlefield in the game. That costs
     * nothing in practice, because terrain is generated once at the start of a
     * fight and lives in the session blob from then on, but it is the reason to
     * leave it alone.
     */
    private static function next(int &$state): int
    {
        $state = ($state + 0x9E3779B9) & 0xFFFFFFFF;
        $z = $state;
        $z = self::mul32($z ^ ($z >> 16), 0x85EBCA6B);
        $z = self::mul32($z ^ ($z >> 13), 0xC2B2AE35);
        return ($z ^ ($z >> 16)) & 0xFFFFFFFF;
    }

    /**
     * A 32-bit multiply that stays 32-bit.
     *
     * PHP integers are 64-bit, and the constants above multiplied by a full
     * 32-bit word overflow into a float — silently, and differently on
     * different builds. Splitting the left operand keeps every partial product
     * inside the exact range.
     *
     * SPLITTING IS NOT ENOUGH ON ITS OWN, and this used to get it wrong. The
     * high partial product is shifted left sixteen before it is masked, and
     * `$hi * $b` can already be near 2^47 — the shift then carries it past
     * PHP_INT_MAX and the whole expression becomes a float, which is exactly
     * the silent, build-dependent result the split exists to prevent. It fired
     * on about one map in a hundred and fifty, and only for seeds with the top
     * bits set, which is what `crc32()` and `random_int()` hand over.
     *
     * Masking to sixteen bits before the shift is free and loses nothing: the
     * final `& 0xFFFFFFFF` was going to discard every bit above the low
     * sixteen of that partial product anyway.
     */
    private static function mul32(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $hi = ($a >> 16) & 0xFFFF;
        $lo = $a & 0xFFFF;
        return (((($hi * $b) & 0xFFFF) << 16) + ($lo * $b)) & 0xFFFFFFFF;
    }

    private static function rint(int &$state, int $lo, int $hi): int
    {
        if ($hi <= $lo) {
            return $lo;
        }
        return $lo + (self::next($state) % ($hi - $lo + 1));
    }
}
