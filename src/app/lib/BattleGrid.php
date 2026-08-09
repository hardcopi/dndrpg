<?php
/**
 * The geometry of a battle: distance, sight, cover, movement and templates.
 *
 * Combat used to have no floor. A combatant's whole position was one enum —
 * front rank or back — and `CombatEngine::legalTargets()` turned that into the
 * entire targeting rule. This class is the floor. Everything spatial the engine
 * needs to know is answered here, and nothing here knows what a spell is, what
 * a feat does, or that a database exists: it is pure static arithmetic over a
 * terrain array and a list of combatants carrying `x`, `y` and `side`.
 *
 * That purity is the point. `tools/test_combat.php` requires this file directly
 * and exercises every rule below without MySQL running, which is the only
 * practical way to be confident about a cone.
 *
 * A NOTE ON DISTANCE. Every square is five feet, including diagonals — the SRD
 * default, i.e. Chebyshev. The 5-10-5 variant is more faithful to a table, and
 * it was rejected deliberately: under 5-10-5 the cost of reaching a cell depends
 * on which path you took, so the reachable set stops being a search over static
 * edge weights and straight-line range stops agreeing with movement cost. The
 * visible symptom would be the board offering a move the server then refuses.
 * Here "can I get there with thirty feet" and "is that within thirty feet" are
 * the same integer by construction.
 *
 * A NOTE ON SYMMETRY. `losClear()` normalises its endpoints before tracing, so
 * the same pair of cells always traces the same rays and sight is symmetric by
 * construction rather than by luck. Asymmetric line of sight is the classic
 * tactical-grid bug and here it would mean the monster AI and the player
 * disagreeing about what is shootable. Cover is deliberately *not* symmetric —
 * see coverBetween().
 */

declare(strict_types=1);

final class BattleGrid
{
    /** The board. Sixteen by twelve at five feet a cell is eighty by sixty. */
    public const W = 16;
    public const H = 12;
    public const CELL_FT = 5;

    /**
     * Cover as the bonus it grants to AC and Dexterity saves.
     *
     * There is no COVER_TOTAL. Total cover means "cannot be targeted at all",
     * which is a question about sight, not about a bonus — ask losClear(), and
     * legalTargets() does.
     */
    public const COVER_NONE = 0;
    public const COVER_HALF = 2;
    public const COVER_THREE_QUARTERS = 5;

    /**
     * Flanking is a Dungeon Master's Guide optional rule, not SRD, and the rest
     * of this codebase is scrupulously SRD-scoped. It is switched on because a
     * grid without it makes surrounding somebody pointless, but it is switched
     * on *here*, in one named place, so turning it back off is a one-line edit
     * rather than an excavation.
     */
    public const FLANKING = true;

    /**
     * What a terrain character means.
     *
     * `cost` is the feet it takes to enter, or null for impassable. `opaque`
     * blocks sight. `cover` is the *most* cover this obstacle can grant — the
     * degree is decided by how many rays it blocks (see coverBetween), but a
     * tavern table can never give more than half however you crouch behind it.
     *
     * `v` and `n` differ in exactly one bit and that bit earns its keep: you
     * cannot walk over a hole, but you can shoot across one.
     */
    public const TERRAIN = [
        '.' => ['cost' => 5,    'opaque' => false, 'cover' => 0, 'label' => 'open ground'],
        ',' => ['cost' => 10,   'opaque' => false, 'cover' => 0, 'label' => 'rough ground'],
        '~' => ['cost' => 10,   'opaque' => false, 'cover' => 0, 'label' => 'shallow water'],
        'o' => ['cost' => null, 'opaque' => false, 'cover' => 2, 'label' => 'low cover'],
        'n' => ['cost' => null, 'opaque' => true,  'cover' => 5, 'label' => 'tall cover'],
        '#' => ['cost' => null, 'opaque' => true,  'cover' => 5, 'label' => 'wall'],
        'v' => ['cost' => null, 'opaque' => false, 'cover' => 0, 'label' => 'a pit'],
    ];

    /** Anything not in TERRAIN, and anything off the board, reads as this. */
    private const UNKNOWN = ['cost' => null, 'opaque' => true, 'cover' => 5, 'label' => 'solid rock'];

    /**
     * Ray results memoised per terrain.
     *
     * Terrain is generated once at startEncounter() and never changes during a
     * fight, so the expensive half of cover and sight — which cells does a
     * corner-to-corner ray cross — is worth keeping. The cache is dropped
     * wholesale when a different terrain arrives, which happens once per
     * request at most.
     */
    private static array $rayCache = [];
    private static array $rayCacheFor = [];

    // -----------------------------------------------------------------------
    // Cells and distance
    // -----------------------------------------------------------------------

    public static function keyOf(int $x, int $y): string
    {
        return $x . ',' . $y;
    }

    /** @return array{0:int,1:int} */
    public static function parseKey(string $key): array
    {
        $parts = explode(',', $key);
        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

    public static function inBounds(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < self::W && $y < self::H;
    }

    /** Distance in cells. Chebyshev — a diagonal is one cell, same as a step. */
    public static function cells(int $ax, int $ay, int $bx, int $by): int
    {
        return max(abs($ax - $bx), abs($ay - $by));
    }

    /** The same distance in feet, which is what every rule is written in. */
    public static function feet(int $ax, int $ay, int $bx, int $by): int
    {
        return self::cells($ax, $ay, $bx, $by) * self::CELL_FT;
    }

    /** Feet rounded up to whole cells — the radius of a template, say. */
    public static function ftToCells(int $ft): int
    {
        return (int) ceil($ft / self::CELL_FT);
    }

    /**
     * The terrain character at a cell. Off the board reads as solid rock, so a
     * ray that leaves the world is blocked rather than an out-of-range notice.
     *
     * @param string[] $terrain H strings of W characters
     */
    public static function at(array $terrain, int $x, int $y): string
    {
        if (!self::inBounds($x, $y)) {
            return '?';
        }
        $row = $terrain[$y] ?? '';
        return $row[$x] ?? '?';
    }

    /** @return array{cost:?int, opaque:bool, cover:int, label:string} */
    public static function info(array $terrain, int $x, int $y): array
    {
        return self::TERRAIN[self::at($terrain, $x, $y)] ?? self::UNKNOWN;
    }

    /** Feet to enter this cell, or null if nothing walks there. */
    public static function enterCost(array $terrain, int $x, int $y): ?int
    {
        return self::info($terrain, $x, $y)['cost'];
    }

    public static function passable(array $terrain, int $x, int $y): bool
    {
        return self::enterCost($terrain, $x, $y) !== null;
    }

    // -----------------------------------------------------------------------
    // Rays: the shared machinery under sight and cover
    // -----------------------------------------------------------------------

    /**
     * What a segment passes through, as a list of steps.
     *
     * Each step is the set of cells the segment could be said to be inside at
     * that point. Usually one. When the segment is running exactly along a grid
     * line — which corner-to-corner rays do constantly — it is the two cells
     * either side of that line, and at a lattice point it is all four.
     *
     * THE RULE THAT MATTERS: a step obstructs only if *every* cell in it
     * obstructs. Shooting along the face of a wall is a clear shot, because the
     * open side of the line is open. Shooting along the seam between two walls
     * is not, because both sides are solid. Getting this wrong in the obvious
     * direction — skipping any sample that lands on a grid line — silently lets
     * every ray thread the join between two wall cells, and the visible symptom
     * is arrows through masonry.
     *
     * Found by collecting every parameter at which the segment crosses a grid
     * line and sampling the midpoint of each resulting interval. Exact and
     * order-independent, which a hand-rolled supercover walk is not.
     *
     * @return array<int, array<int, array{0:int,1:int}>>
     */
    private static function segmentSteps(float $x0, float $y0, float $x1, float $y1): array
    {
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        if (abs($dx) < 1e-9 && abs($dy) < 1e-9) {
            return [];
        }

        $ts = [0.0, 1.0];
        if (abs($dx) > 1e-9) {
            $lo = (int) floor(min($x0, $x1));
            $hi = (int) ceil(max($x0, $x1));
            for ($gx = $lo; $gx <= $hi; $gx++) {
                $t = ($gx - $x0) / $dx;
                if ($t > 1e-9 && $t < 1 - 1e-9) {
                    $ts[] = $t;
                }
            }
        }
        if (abs($dy) > 1e-9) {
            $lo = (int) floor(min($y0, $y1));
            $hi = (int) ceil(max($y0, $y1));
            for ($gy = $lo; $gy <= $hi; $gy++) {
                $t = ($gy - $y0) / $dy;
                if ($t > 1e-9 && $t < 1 - 1e-9) {
                    $ts[] = $t;
                }
            }
        }
        sort($ts);

        $out = [];
        $n = count($ts);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($ts[$i + 1] - $ts[$i] < 1e-9) {
                continue;
            }
            $tm = ($ts[$i] + $ts[$i + 1]) / 2.0;
            $px = $x0 + $dx * $tm;
            $py = $y0 + $dy * $tm;

            $onX = abs($px - round($px)) < 1e-9;
            $onY = abs($py - round($py)) < 1e-9;
            $cx = $onX ? (int) round($px) : (int) floor($px);
            $cy = $onY ? (int) round($py) : (int) floor($py);

            if (!$onX && !$onY) {
                $out[] = [[$cx, $cy]];
            } elseif ($onX && !$onY) {
                $out[] = [[$cx - 1, $cy], [$cx, $cy]];
            } elseif (!$onX && $onY) {
                $out[] = [[$cx, $cy - 1], [$cx, $cy]];
            } else {
                $out[] = [[$cx - 1, $cy - 1], [$cx, $cy - 1], [$cx - 1, $cy], [$cx, $cy]];
            }
        }
        return $out;
    }

    /** The four corners of a cell, in corner coordinates. */
    private static function corners(int $x, int $y): array
    {
        return [[$x, $y], [$x + 1, $y], [$x, $y + 1], [$x + 1, $y + 1]];
    }

    /**
     * Trace all sixteen corner-to-corner rays between two cells and report what
     * they ran into.
     *
     * The SRD cover rule is "pick a corner of your square and trace to all four
     * corners of the target's; one or two blocked is half cover, three or four
     * is three-quarters". Picking is the attacker's, so this takes the best of
     * the four origin corners — that is what lets somebody flush against a
     * pillar lean out and shoot past it.
     *
     * ALL FOUR CORNERS COME BACK, rather than the best one. Which corner is
     * best cannot be decided here: terrain is only half of cover, and the other
     * half is who is standing in the way, which changes every time somebody
     * moves. Picking a corner on the terrain alone and then asking about bodies
     * from *that* corner is subtly wrong in a way that shows up the moment a
     * fight gets crowded — six creatures around one target all reported half
     * cover from each other, because the first corner that tied at zero terrain
     * cover happened to look through somebody. coverBetween() evaluates each
     * corner completely and takes the best of the four.
     *
     * The cache stays keyed on terrain alone, which is the point of splitting
     * it this way: terrain never changes during a fight and bodies never stop.
     *
     * @return array{opaque:int, corners:array<int, array{blocked:int, cap:int, steps:array<int, string[]>}>}
     */
    private static function rays(array $terrain, int $ax, int $ay, int $bx, int $by): array
    {
        if (self::$rayCacheFor !== $terrain) {
            self::$rayCacheFor = $terrain;
            self::$rayCache = [];
        }
        $memo = $ax . ',' . $ay . '>' . $bx . ',' . $by;
        if (isset(self::$rayCache[$memo])) {
            return self::$rayCache[$memo];
        }
        // A whole board is 192 cells, so a fight can ask about 36,864 ordered
        // pairs. It never does, but a runaway cache inside one request would be
        // a memory fault that only shows up on the biggest fights.
        if (count(self::$rayCache) > 20000) {
            self::$rayCache = [];
        }

        // Neither end blocks itself: you get no cover from your own square, and
        // a target does not hide behind the ground it stands on.
        $skip = [self::keyOf($ax, $ay) => true, self::keyOf($bx, $by) => true];
        $targetCorners = self::corners($bx, $by);

        $minOpaque = 5;
        $perCorner = [];

        foreach (self::corners($ax, $ay) as [$cx, $cy]) {
            $opaque = 0;
            $blocked = 0;
            $cap = 0;
            $steps = [];
            foreach ($targetCorners as [$tx, $ty]) {
                $hitOpaque = false;
                $hitCover = 0;
                foreach (self::segmentSteps((float) $cx, (float) $cy, (float) $tx, (float) $ty) as $candidates) {
                    $allOpaque = true;
                    $allCover = true;
                    $stepCap = PHP_INT_MAX;
                    $keys = [];
                    $touchesEnd = false;
                    foreach ($candidates as [$px, $py]) {
                        $key = self::keyOf($px, $py);
                        if (isset($skip[$key])) {
                            // One side of this boundary is the attacker's own
                            // square or the target's, and neither of those ever
                            // obstructs — so the ray grazes past rather than
                            // going through, and the step cannot block at all.
                            // Dropping the endpoint from the set instead and
                            // judging the rest is what made a ring of bodies
                            // round one target all give each other half cover.
                            $touchesEnd = true;
                            break;
                        }
                        $keys[] = $key;
                        $info = self::info($terrain, $px, $py);
                        if (!$info['opaque']) {
                            $allOpaque = false;
                        }
                        if ($info['cover'] <= 0) {
                            $allCover = false;
                        } else {
                            $stepCap = min($stepCap, $info['cover']);
                        }
                    }
                    if ($touchesEnd) {
                        continue;
                    }
                    if ($allOpaque) {
                        $hitOpaque = true;
                    }
                    if ($allCover && $stepCap !== PHP_INT_MAX) {
                        $hitCover = max($hitCover, $stepCap);
                    }
                    if ($keys) {
                        $steps[] = $keys;
                    }
                }
                if ($hitOpaque) {
                    $opaque++;
                }
                if ($hitCover > 0) {
                    $blocked++;
                    $cap = max($cap, $hitCover);
                }
            }
            $minOpaque = min($minOpaque, $opaque);
            $perCorner[] = ['blocked' => $blocked, 'cap' => $cap, 'steps' => $steps];
        }

        $result = ['opaque' => $minOpaque, 'corners' => $perCorner];
        self::$rayCache[$memo] = $result;
        return $result;
    }

    // -----------------------------------------------------------------------
    // Sight
    // -----------------------------------------------------------------------

    /**
     * Can anything at all be seen from one cell to another?
     *
     * False is total cover: the target cannot be attacked, cannot be targeted
     * by a spell, and is not in any template. True says nothing about how much
     * cover there is — ask coverBetween().
     *
     * The endpoints are ordered before tracing so that losClear(a, b) and
     * losClear(b, a) run the identical computation. That is not a tidiness
     * measure. Sight that disagrees with itself means the monster AI shooting
     * through a pillar the player is told is solid, and the bug reads as a
     * targeting fault rather than a geometry one.
     */
    public static function losClear(array $terrain, int $ax, int $ay, int $bx, int $by): bool
    {
        if ([$ax, $ay] > [$bx, $by]) {
            [$ax, $ay, $bx, $by] = [$bx, $by, $ax, $ay];
        }
        return self::rays($terrain, $ax, $ay, $bx, $by)['opaque'] < 4;
    }

    /**
     * Whether anybody is standing between these two, giving the target cover.
     *
     * Only useful for a caller that already knows the terrain is clear; every
     * ordinary question goes through coverBetween(), which weighs both.
     */
    public static function bodyInTheWay(array $terrain, array $combatants, array $attacker, array $target): bool
    {
        return self::coverBetween($terrain, $combatants, $attacker, $target) > self::COVER_NONE
            && self::terrainCoverOnly($terrain, $attacker, $target) === self::COVER_NONE;
    }

    /** Cover from the ground alone, ignoring who is standing on it. */
    public static function terrainCoverOnly(array $terrain, array $attacker, array $target): int
    {
        return self::coverBetween($terrain, [], $attacker, $target);
    }

    // -----------------------------------------------------------------------
    // Cover
    // -----------------------------------------------------------------------

    /**
     * How much cover the target has from this attacker: 0, +2 or +5.
     *
     * Deliberately asymmetric, unlike sight. Cover is the attacker's problem,
     * and the attacker picks the corner to shoot from — so somebody standing
     * against a wall shoots out past it freely while their target, twenty feet
     * back, is still behind it. That is what a table does and it is what makes
     * hugging a pillar worth doing.
     *
     * Two contributions, and the larger wins:
     *
     *  - terrain: the number of blocked rays gives the degree (one or two is
     *    half, three or four is three-quarters), capped by the best any of the
     *    blocking obstacles can offer. A crate is half cover no matter how many
     *    rays clip it.
     *  - creatures: any body in the way is half cover and never more, which is
     *    the SRD rule and also the thing that stops your own front rank from
     *    being a wall.
     *
     * The attacker and target themselves never contribute.
     */
    public static function coverBetween(array $terrain, array $combatants, array $attacker, array $target): int
    {
        $ax = (int) ($attacker['x'] ?? 0);
        $ay = (int) ($attacker['y'] ?? 0);
        $tx = (int) ($target['x'] ?? 0);
        $ty = (int) ($target['y'] ?? 0);

        $r = self::rays($terrain, $ax, $ay, $tx, $ty);

        $selfCid = (string) ($attacker['cid'] ?? '');
        $targetCid = (string) ($target['cid'] ?? '');
        $bodies = [];
        foreach ($combatants as $c) {
            if (empty($c['alive'])) {
                continue;
            }
            $cid = (string) ($c['cid'] ?? '');
            if ($cid === $selfCid || $cid === $targetCid) {
                continue;
            }
            $bodies[self::keyOf((int) ($c['x'] ?? -1), (int) ($c['y'] ?? -1))] = true;
        }

        // Score each corner completely — terrain and bodies together — and take
        // the attacker's best. Judging the corners on terrain first and then
        // asking about bodies from the winner is what made a crowd of adjacent
        // enemies all report half cover from one another.
        $best = self::COVER_THREE_QUARTERS;
        foreach ($r['corners'] as $corner) {
            $terrainCover = self::COVER_NONE;
            if ($corner['blocked'] >= 3) {
                $terrainCover = self::COVER_THREE_QUARTERS;
            } elseif ($corner['blocked'] >= 1) {
                $terrainCover = self::COVER_HALF;
            }
            $terrainCover = min($terrainCover, $corner['cap']);

            // Same rule as terrain: a step only obstructs if every cell it
            // could be in obstructs, so a ray sliding along the edge of
            // somebody's square goes past them rather than through. A body is
            // half cover and never more, which is the SRD rule and the thing
            // that stops your own front line being a wall.
            $creatureCover = self::COVER_NONE;
            foreach ($corner['steps'] as $keys) {
                $all = $keys !== [];
                foreach ($keys as $key) {
                    if (!isset($bodies[$key])) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $creatureCover = self::COVER_HALF;
                    break;
                }
            }

            $best = min($best, max($terrainCover, $creatureCover));
            if ($best === self::COVER_NONE) {
                break;
            }
        }

        return $best;
    }

    // -----------------------------------------------------------------------
    // Movement
    // -----------------------------------------------------------------------

    /**
     * Every cell the actor can reach with a budget of feet, and what it cost.
     *
     * A Dijkstra over two edge weights — five feet for open ground, ten for
     * rough — done with cost buckets rather than a heap, because with a handful
     * of distinct costs the bucket is both faster and easier to read.
     *
     * Occupancy follows the SRD: a hostile body is a wall, a friendly one can
     * be squeezed past but not stood on. That second rule matters more than it
     * looks on a sixteen-wide board, because without it your own front line
     * pens the rest of the party in.
     *
     * Diagonals do not cut corners. Stepping from (x,y) to (x+1,y+1) needs at
     * least one of (x+1,y) and (x,y+1) to be walkable, so nobody slips between
     * the corners of two walls. Without it the shortest path across a room
     * routinely threads a gap that is not there, and it reads as walking
     * through scenery.
     *
     * The `from` field on every entry is the predecessor the search already
     * built. pathTo() walks it back, and the client is handed the whole tree so
     * that hovering a cell can draw the exact path the server would take —
     * which is the only honest way to preview a move.
     *
     * STRAIGHTNESS IS PART OF THE ANSWER, NOT A GARNISH. Under Chebyshev a
     * diagonal costs what a step costs, so almost every destination has a great
     * many routes of exactly equal price and which one gets recorded is decided
     * purely by tie-break. Left to the enumeration order, ten cells due east
     * came back as ten diagonals zigzagging about the straight line — same
     * fifty feet, and the board animated the walk faithfully, so the party
     * appeared to be unable to walk in a straight line. So the search minimises
     * a pair: cost first, then the number of TURNS, which is what makes a path
     * look like somebody walking rather than a tie being broken.
     *
     * `turns` is carried on the entry for that comparison and is not a fact
     * about the board — `pathTo()` ignores it and the client is not shown it.
     *
     * @return array<string, array{cost:int, from:?string, end:bool, turns:int,
     *                              diags:int, dir:?string}>
     */
    public static function reachable(array $terrain, array $combatants, array $actor, int $budgetFt): array
    {
        $sx = (int) ($actor['x'] ?? 0);
        $sy = (int) ($actor['y'] ?? 0);
        $side = (string) ($actor['side'] ?? 'party');
        $selfCid = (string) ($actor['cid'] ?? '');

        // hostile => cannot pass; friendly (or a downed body) => pass, don't stop.
        $blocked = [];
        $occupied = [];
        foreach ($combatants as $c) {
            if (empty($c['alive'])) {
                continue;
            }
            $cid = (string) ($c['cid'] ?? '');
            if ($cid === $selfCid) {
                continue;
            }
            $key = self::keyOf((int) ($c['x'] ?? -1), (int) ($c['y'] ?? -1));
            $occupied[$key] = true;
            if ((string) ($c['side'] ?? '') !== $side && self::upright($c)) {
                $blocked[$key] = true;
            }
        }

        $start = self::keyOf($sx, $sy);
        $out = [$start => [
            'cost' => 0, 'from' => null, 'end' => true,
            'turns' => 0, 'diags' => 0, 'dir' => null,
        ]];
        if ($budgetFt <= 0) {
            return $out;
        }

        $buckets = [0 => [$start]];
        $cursor = 0;
        $max = $budgetFt;

        while ($cursor <= $max) {
            if (empty($buckets[$cursor])) {
                $cursor++;
                continue;
            }
            $key = array_pop($buckets[$cursor]);
            if ($out[$key]['cost'] !== $cursor) {
                continue;   // already settled cheaper
            }
            [$cx, $cy] = self::parseKey($key);
            $dirIn = $out[$key]['dir'];
            $turnsHere = $out[$key]['turns'];
            $diagsHere = $out[$key]['diags'];

            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    if ($dx === 0 && $dy === 0) {
                        continue;
                    }
                    $nx = $cx + $dx;
                    $ny = $cy + $dy;
                    if (!self::inBounds($nx, $ny)) {
                        continue;
                    }
                    $step = self::enterCost($terrain, $nx, $ny);
                    if ($step === null) {
                        continue;
                    }
                    $nkey = self::keyOf($nx, $ny);
                    if (isset($blocked[$nkey])) {
                        continue;
                    }
                    if ($dx !== 0 && $dy !== 0
                        && !self::passable($terrain, $cx + $dx, $cy)
                        && !self::passable($terrain, $cx, $cy + $dy)) {
                        continue;   // no squeezing between two corners
                    }
                    $cost = $cursor + $step;
                    if ($cost > $max) {
                        continue;
                    }
                    // Carrying straight on is free; changing heading is a turn.
                    // The first step out of the origin has no heading to keep,
                    // so it never counts as one.
                    $dir = $dx . ',' . $dy;
                    $turns = $turnsHere + ($dirIn !== null && $dirIn !== $dir ? 1 : 0);
                    // Fewest turns is not on its own enough. Eight cells east
                    // and two south can be walked as six straight then two
                    // diagonal, or as five diagonals down and three back up —
                    // both cost forty feet and both bend exactly once, and the
                    // second is a V for no reason. Diagonals are the tie-break
                    // under that: spend them only where they buy ground.
                    $diags = $diagsHere + (($dx !== 0 && $dy !== 0) ? 1 : 0);

                    if (isset($out[$nkey])) {
                        if ($out[$nkey]['cost'] < $cost) {
                            continue;
                        }
                        // Same price: keep whichever route bends less, then
                        // whichever leans less. Equal on all three means keep
                        // what is there, so the result does not depend on the
                        // order the neighbours happen to be enumerated in.
                        if ($out[$nkey]['cost'] === $cost
                            && ($out[$nkey]['turns'] < $turns
                                || ($out[$nkey]['turns'] === $turns && $out[$nkey]['diags'] <= $diags))) {
                            continue;
                        }
                    }
                    $out[$nkey] = [
                        'cost'  => $cost,
                        'from'  => $key,
                        'end'   => !isset($occupied[$nkey]),
                        'turns' => $turns,
                        'diags' => $diags,
                        'dir'   => $dir,
                    ];
                    $buckets[$cost][] = $nkey;
                }
            }
            // The cursor only ever moves forward for COST: every step costs at
            // least five feet, so no cell is ever given a cheaper price than
            // the bucket being drained. That is what makes buckets correct here
            // without a heap.
            //
            // A straighter route to a cell already priced at this cost is the
            // one case that re-enters the bucket being drained, and it
            // terminates for its own reason: it is only taken when `turns`
            // strictly decreases, and turns cannot fall below zero.
        }

        return $out;
    }

    /**
     * The path to a destination, origin first, as cell keys.
     *
     * Walks the predecessor chain reachable() recorded, so the summed step
     * costs always equal the recorded cost of the destination — an invariant
     * worth testing, because a path that disagrees with its own price is how a
     * move ends up costing the wrong number of feet.
     *
     * @param array<string, array{cost:int, from:?string, end:bool}> $reach
     * @return string[] empty if the destination was never reached
     */
    public static function pathTo(array $reach, string $destKey): array
    {
        if (!isset($reach[$destKey])) {
            return [];
        }
        $path = [];
        $at = $destKey;
        $guard = 0;
        while ($at !== null && $guard++ < self::W * self::H + 2) {
            array_unshift($path, $at);
            $at = $reach[$at]['from'] ?? null;
        }
        return $path;
    }

    // -----------------------------------------------------------------------
    // Threat, opportunity and flanking
    // -----------------------------------------------------------------------

    /**
     * Which cells each of these combatants can make a melee attack into.
     *
     * The threateners are handed in already filtered — the caller decides who
     * is conscious, who can act and who still has a reaction, because those are
     * rules questions and this class does not answer rules questions. Each one
     * needs `x`, `y`, `cid` and optionally `reach_ft` (five if absent).
     *
     * Sight is required, so a halberd does not reach through a wall.
     *
     * @return array<string, string[]> cell key => the cids that threaten it
     */
    public static function threatenedCells(array $terrain, array $threateners): array
    {
        $out = [];
        foreach ($threateners as $t) {
            $tx = (int) ($t['x'] ?? -1);
            $ty = (int) ($t['y'] ?? -1);
            if (!self::inBounds($tx, $ty)) {
                continue;
            }
            // An explicit reach of zero means this creature threatens nothing —
            // an archer with no melee attack does not get a free swing at
            // somebody walking past. An *absent* reach still defaults to five
            // feet, because a combatant without the field is old state rather
            // than a creature with no arms.
            $r = self::ftToCells((int) ($t['reach_ft'] ?? self::CELL_FT));
            if ($r < 1) {
                continue;
            }
            $cid = (string) ($t['cid'] ?? '');
            for ($y = $ty - $r; $y <= $ty + $r; $y++) {
                for ($x = $tx - $r; $x <= $tx + $r; $x++) {
                    if (!self::inBounds($x, $y) || ($x === $tx && $y === $ty)) {
                        continue;
                    }
                    if (self::cells($tx, $ty, $x, $y) > $r) {
                        continue;
                    }
                    if (!self::losClear($terrain, $tx, $ty, $x, $y)) {
                        continue;
                    }
                    $out[self::keyOf($x, $y)][] = $cid;
                }
            }
        }
        return $out;
    }

    /**
     * The subset of those threateners who reach one particular cell.
     *
     * Opportunity attacks compare this at the cell being left against the cell
     * being entered: leaving somebody's reach provokes, circling inside it does
     * not. That difference is the whole SRD rule and it is cheaper to ask twice
     * than to build the full map.
     *
     * @return string[] cids
     */
    public static function threatenersOf(array $terrain, array $threateners, int $x, int $y): array
    {
        $out = [];
        foreach ($threateners as $t) {
            $tx = (int) ($t['x'] ?? -1);
            $ty = (int) ($t['y'] ?? -1);
            if (!self::inBounds($tx, $ty) || ($tx === $x && $ty === $y)) {
                continue;
            }
            $r = self::ftToCells((int) ($t['reach_ft'] ?? self::CELL_FT));
            if ($r < 1 || self::cells($tx, $ty, $x, $y) > $r) {
                continue;
            }
            if (!self::losClear($terrain, $tx, $ty, $x, $y)) {
                continue;
            }
            $out[] = (string) ($t['cid'] ?? '');
        }
        return $out;
    }

    /**
     * Is the attacker flanking, i.e. is an ally directly opposite them?
     *
     * "Directly opposite" is exact collinearity through the target's centre —
     * the ally's cell is the attacker's cell reflected in the target. Both must
     * be within their own melee reach; the allies are handed in pre-filtered
     * for consciousness, as with threatenedCells().
     *
     * @param array<int, array> $allies the attacker's side, attacker excluded
     */
    public static function flanking(array $terrain, array $attacker, array $allies, array $target): bool
    {
        if (!self::FLANKING) {
            return false;
        }
        $ax = (int) ($attacker['x'] ?? 0);
        $ay = (int) ($attacker['y'] ?? 0);
        $tx = (int) ($target['x'] ?? 0);
        $ty = (int) ($target['y'] ?? 0);

        $ar = self::ftToCells((int) ($attacker['reach_ft'] ?? self::CELL_FT));
        if ($ar < 1 || self::cells($ax, $ay, $tx, $ty) > $ar) {
            return false;
        }

        $wantX = 2 * $tx - $ax;
        $wantY = 2 * $ty - $ay;
        foreach ($allies as $a) {
            if ((int) ($a['x'] ?? -1) !== $wantX || (int) ($a['y'] ?? -1) !== $wantY) {
                continue;
            }
            $r = self::ftToCells((int) ($a['reach_ft'] ?? self::CELL_FT));
            if ($r < 1 || self::cells($wantX, $wantY, $tx, $ty) > $r) {
                continue;
            }
            if (self::losClear($terrain, $wantX, $wantY, $tx, $ty)) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------------

    /**
     * The cells a spell's area covers.
     *
     * `$ox,$oy` is the caster (the origin of a cone, line or Self radius) and
     * `$tx,$ty` is where it is aimed — the centre for a radius or cube placed
     * at range, the direction for a cone or line.
     *
     * Every result is filtered by sight from the origin, so a fireball does not
     * turn a corner and a cone does not spill through a wall.
     *
     * THE CONE RULE, WRITTEN DOWN. A cone of length n cells is as wide as it is
     * long: a cell at distance d is inside it when its perpendicular offset
     * from the aiming axis is at most d/2. Directions snap to the eight
     * compass points. That gives seven cells for a fifteen-foot cone, cardinal
     * or diagonal, and `tools/test_combat.php` asserts the exact set. This is
     * the most-argued question at any table and a written answer is the only
     * defence.
     *
     * @return string[] cell keys
     */
    public static function template(
        array $terrain,
        string $shape,
        int $ft,
        int $ox,
        int $oy,
        int $tx,
        int $ty
    ): array {
        $n = max(1, self::ftToCells($ft));
        $cells = [];

        switch ($shape) {
            case 'radius':
            case 'sphere':
                for ($y = $ty - $n; $y <= $ty + $n; $y++) {
                    for ($x = $tx - $n; $x <= $tx + $n; $x++) {
                        if (self::inBounds($x, $y) && self::cells($tx, $ty, $x, $y) <= $n) {
                            $cells[] = [$x, $y];
                        }
                    }
                }
                break;

            case 'cube':
                // Centred on the aim point, which keeps a cube placeable with
                // one click instead of asking which corner somebody meant.
                $half = intdiv($n - 1, 2);
                for ($y = $ty - $half; $y < $ty - $half + $n; $y++) {
                    for ($x = $tx - $half; $x < $tx - $half + $n; $x++) {
                        if (self::inBounds($x, $y)) {
                            $cells[] = [$x, $y];
                        }
                    }
                }
                break;

            case 'line':
                [$dx, $dy] = self::direction($ox, $oy, $tx, $ty);
                for ($i = 1; $i <= $n; $i++) {
                    $x = $ox + $dx * $i;
                    $y = $oy + $dy * $i;
                    if (self::inBounds($x, $y)) {
                        $cells[] = [$x, $y];
                    }
                }
                break;

            case 'cone':
                [$dx, $dy] = self::direction($ox, $oy, $tx, $ty);
                for ($y = $oy - $n; $y <= $oy + $n; $y++) {
                    for ($x = $ox - $n; $x <= $ox + $n; $x++) {
                        if (!self::inBounds($x, $y) || ($x === $ox && $y === $oy)) {
                            continue;
                        }
                        $d = self::cells($ox, $oy, $x, $y);
                        if ($d > $n) {
                            continue;
                        }
                        $w = self::coneOffset($dx, $dy, $x - $ox, $y - $oy);
                        if ($w !== null && $w * 2 <= $d) {
                            $cells[] = [$x, $y];
                        }
                    }
                }
                break;

            default:
                return [];
        }

        $out = [];
        foreach ($cells as [$x, $y]) {
            if (self::losClear($terrain, $ox, $oy, $x, $y)) {
                $out[] = self::keyOf($x, $y);
            }
        }
        return $out;
    }

    /**
     * The offset of a cell from the cone's axis, or null if it is behind the
     * caster and so outside the cone whatever its distance.
     *
     * For a cardinal aim the axis is one coordinate and the offset is the
     * other. For a diagonal aim the axis is the diagonal itself and the offset
     * is how far the cell has slipped off it, which is the difference between
     * the two coordinate magnitudes.
     */
    private static function coneOffset(int $dirX, int $dirY, int $dx, int $dy): ?int
    {
        if ($dirX !== 0 && $dirY !== 0) {
            if ($dx * $dirX <= 0 || $dy * $dirY <= 0) {
                return null;
            }
            return abs(abs($dx) - abs($dy));
        }
        if ($dirX !== 0) {
            return $dx * $dirX <= 0 ? null : abs($dy);
        }
        return $dy * $dirY <= 0 ? null : abs($dx);
    }

    /**
     * The aim snapped to one of the eight compass directions.
     *
     * A cone or line aimed at your own cell has no direction; east is the
     * arbitrary answer, chosen so the template is still drawable rather than
     * empty. The client never sends that case, but the AI can.
     *
     * @return array{0:int,1:int}
     */
    public static function direction(int $ox, int $oy, int $tx, int $ty): array
    {
        $dx = $tx - $ox;
        $dy = $ty - $oy;
        if ($dx === 0 && $dy === 0) {
            return [1, 0];
        }
        // Snap: a component counts as diagonal once it is at least half the
        // other, which puts the boundaries at the 22.5-degree bisectors.
        $ax = abs($dx);
        $ay = abs($dy);
        $sx = $dx === 0 ? 0 : ($dx > 0 ? 1 : -1);
        $sy = $dy === 0 ? 0 : ($dy > 0 ? 1 : -1);
        if ($ax > $ay * 2) {
            return [$sx, 0];
        }
        if ($ay > $ax * 2) {
            return [0, $sy];
        }
        return [$sx, $sy];
    }

    // -----------------------------------------------------------------------

    /**
     * A body that is still standing in the way.
     *
     * Purely mechanical — `alive` and not flat on the floor. The engine's
     * richer isTargetable() is a rules question and stays where the rules are;
     * this is only ever asked about whether a square is walkable.
     */
    private static function upright(array $c): bool
    {
        if (empty($c['alive'])) {
            return false;
        }
        foreach ((array) ($c['conditions'] ?? []) as $cond) {
            $key = is_array($cond) ? ($cond['key'] ?? '') : $cond;
            if ($key === 'unconscious') {
                return false;
            }
        }
        return true;
    }
}
