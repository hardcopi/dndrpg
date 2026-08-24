<?php
/**
 * Battlefields drawn by hand instead of generated.
 *
 * BattleMapGen builds a board out of a palette and a seed, which is the right
 * answer for the hundreds of places a party can be jumped: nobody is going to
 * draw a map for every stretch of road. It is the wrong answer for the few
 * places that are somewhere in particular — a named hall, a set-piece, the yard
 * everyone starts in — and this is where those live.
 *
 * A fixed map returns the same shape generate() does, so nothing downstream
 * knows the difference: CombatEngine deploys into it, BattleGrid reads it for
 * cover and sight, and the client draws it. The one addition is `image`, a
 * picture the client lays under the grid instead of tiling a floor texture.
 *
 * THE PICTURE AND THE TERRAIN ARE ONE THING, and that is the whole discipline
 * here. A backdrop with generated terrain over it would be a map that lies —
 * a wall you can see and shoot through, a crate drawn on open floor. So the
 * terrain below is authored against the image, cell by cell: `#` where the
 * picture has a wall, `o` where it has a table you can shoot over, `,` on the
 * stairs, and a gap in row 10 exactly where the front door is drawn.
 *
 * If the image is ever replaced, the terrain has to be redrawn with it.
 */

declare(strict_types=1);

class FixedBattleMaps
{
    /**
     * Terrain legend, from BattleGrid::TERRAIN:
     *   `.` open   `,` rough (double cost)   `~` shallow water
     *   `o` low cover — cannot cross, can see and shoot over
     *   `n` tall cover — cannot cross, blocks sight (trees, garden wall)
     *   `#` wall     `v` pit
     */
    private const MAPS = [
        // The Proving Yard: the fighting pit under the floor of the inn.
        //
        // Every fight this location serves is a pit bout — Ilsa sends you down
        // the stairs, something comes out, and you settle it. So the board is
        // the pit and not the common room above it: a ring of beaten earth with
        // a timber wall round it, torches, and nothing whatever to hide behind.
        //
        // The bare floor is the picture's, not an omission. A pit is a place
        // built so that two things have to meet, and cover would be somebody
        // cheating. It also means this board has no `o` in it at all, which is
        // the first fixed map here that does not.
        //
        // The wall is drawn along the top and bottom of the frame and runs off
        // the left and right of it — a 4:3 board cannot hold a 16:9 arena and
        // keep all four walls, so the sides are cropped and the boundary column
        // is wall the picture does not show. That is the ordinary framing of a
        // battle map and reads as the arena continuing past the edge.
        '_freeplay_yard' => [
            'image'   => 'battlemaps/proving_pit.jpg',
            'terrain' => [
                '################',
                '#..............#',
                '#..............#',
                '#..............#',
                '#..............#',
                '#..............#',
                '#..............#',
                '#..............#',
                '#..............#',
                '#..............#',
                '#..............#',
                '################',
            ],
            // Opposite ends of the floor, which is what a pit is for. Wider
            // than the inn's zones because there is room here and nothing in
            // the way of it.
            'zones'   => [
                'party' => [1, 4, 3, 7],
                'foe'   => [12, 4, 14, 7],
            ],
        ],
    ];

    /** Whether this location has a map of its own. */
    public static function has(string $locationKey): bool
    {
        return isset(self::MAPS[$locationKey]);
    }

    /**
     * The board for a location, in the shape generate() returns, or null.
     *
     * `seed` is carried through and `palette` says `fixed` so a stored fight
     * still round-trips: nothing re-generates from the seed, but the columns
     * that record how a board was made keep saying something true.
     *
     * No `props`: the furniture is in the picture. A generated barrel drawn on
     * top of a drawn table is exactly the disagreement this class exists to
     * avoid.
     */
    public static function get(string $locationKey, int $seed): ?array
    {
        $map = self::MAPS[$locationKey] ?? null;
        if ($map === null) {
            return null;
        }

        return [
            'w'       => BattleGrid::W,
            'h'       => BattleGrid::H,
            'cell_ft' => BattleGrid::CELL_FT,
            'seed'    => $seed,
            'palette' => 'fixed',
            'terrain' => $map['terrain'],
            'image'   => $map['image'],
            'floor'   => null,
            'wall'    => null,
            'props'   => [],
            'zones'   => $map['zones'],
        ];
    }
}
