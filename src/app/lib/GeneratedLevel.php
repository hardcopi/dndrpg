<?php
/**
 * A floor from the map service, turned into a level this game can write.
 *
 * The service hands back a `Dungeon` — rooms as rectangles on a tile grid,
 * doors on their walls, features, a carved floor bitmap, and prose. DelveEngine
 * wants what DungeonGen::generate() returns. This is the join, and it is
 * deliberately thin: it decides the SHAPE of the place and nothing about the
 * game played in it. Stocking, traps, doors, atmosphere and the passages' own
 * prose all come from DungeonGen::finish(), which is the same pass that
 * finishes a floor this game generated itself.
 *
 * So a delve is the same delve whichever generator drew it. That is the whole
 * design: one pipeline, two layout sources.
 *
 * THE HARD PART IS THE GRAPH. A `Dungeon` says where the rooms are and carves
 * corridors into a bitmap, but never says which rooms a corridor joins — and
 * "which rooms are connected" is what a delve is made of, because every room
 * and every passage becomes a location you walk between. So the connectivity is
 * read back out of the floor the generator carved; see edgesFrom().
 */

declare(strict_types=1);

final class GeneratedLevel
{
    /**
     * A level, from one of the service's dungeons.
     *
     * `$seed` and `$depth` are the delve's own, not the service's — they are
     * what the rest of the engine stores and replays, and the service's seed is
     * derived from them by MapService::seedFor.
     *
     * Returns null when the dungeon cannot make a level worth walking: fewer
     * than two rooms, or a layout nothing connects. The caller falls back to
     * DungeonGen, which is the same answer it gives when the service is down.
     */
    public static function fromDungeon(array $dungeon, int $seed, int $depth): ?array
    {
        $cols = (int) ($dungeon['cols'] ?? 0);
        $rows = (int) ($dungeon['rows'] ?? 0);
        $src = $dungeon['rooms'] ?? [];
        if ($cols < 4 || $rows < 4 || count($src) < 2) {
            return null;
        }

        // Rooms, in the two coordinate systems the chart needs at once: the
        // lattice (gx, gy, w, h) that the fog raster and the passage router
        // measure in, and the view point (x, y) the label hangs off. Both come
        // straight from the generator's own rectangle — the lattice IS the
        // generator's tile grid now, which is the point of gridOf().
        $rooms = [];
        foreach ($src as $i => $r) {
            $gx = max(0, (int) $r['x']);
            $gy = max(0, (int) $r['y']);
            $w = max(1, (int) $r['w']);
            $h = max(1, (int) $r['h']);
            [$x, $y] = DungeonGen::project($gx + $w / 2, $gy + $h / 2, $cols, $rows);
            $rooms[] = [
                'id'          => $i,
                'gx'          => $gx,
                'gy'          => $gy,
                'w'           => min($w, $cols - $gx),
                'h'           => min($h, $rows - $gy),
                'x'           => $x,
                'y'           => $y,
                // Filled by DungeonGen::finish(). Present because every later
                // pass reads them, and a missing key is a warning rather than
                // an answer.
                'kind'        => 'empty',
                'role'        => 'room',
                'name'        => '',
                'description' => '',
            ];
        }

        $edges = self::edgesFrom($dungeon, $rooms, $cols, $rows);
        if ($edges === []) {
            return null;
        }

        $profile = DungeonGen::profileForDepth($depth);
        // The delve's own stream, mixed exactly as DungeonGen::generate mixes
        // it, so a floor is as reproducible from its seed here as there.
        $rng = ($seed ^ ($depth * 0x9E3779B1)) & 0xFFFFFFFF;

        $level = DungeonGen::finish($rng, $seed, $depth, $profile, $rooms, $edges, self::prose($dungeon));

        // The lattice, so plan(), tiles() and routes() measure in the
        // generator's tiles rather than in the nine-by-seven slots every level
        // used to be drawn on. `sub` is 1 because these rooms are already
        // measured in tiles. See DungeonGen::gridOf().
        $level['grid_w'] = $cols;
        $level['grid_h'] = $rows;
        $level['sub'] = 1;
        $level['source'] = 'mapgen';
        $level['title'] = (string) ($dungeon['lore']['title'] ?? '');
        $level['blurb'] = (string) ($dungeon['lore']['blurb'] ?? '');

        // The floor's errand, carried through untouched for DelveEngine to
        // write into the journal. Not turned into anything here: this class
        // adapts geometry, and a quest is not geometry.
        if (!empty($dungeon['lore']['quest'])) {
            $level['quest'] = $dungeon['lore']['quest'];
        }

        return DungeonGen::isWalkable($level) ? $level : null;
    }

    /**
     * Which rooms are joined, read out of the floor the generator carved.
     *
     * The bitmap is the only place the answer exists. Every open tile that is
     * not inside a room is corridor; flood-fill those into components; a
     * component that touches two or more rooms joins them. Rooms that touch each
     * other directly, with a door and no passage between, are joined too.
     *
     * ONE EDGE PER PAIR, and pairs from the same component are chained rather
     * than joined all-to-all. A hall that four corridors meet in would otherwise
     * become six passages between four rooms, and every one of them would be
     * drawn and walked as a separate place.
     *
     * @return list<array{a:int,b:int,door:string}>
     */
    private static function edgesFrom(array $dungeon, array $rooms, int $cols, int $rows): array
    {
        $floor = $dungeon['floor'] ?? [];
        $n = $cols * $rows;

        // Which room owns each tile, or null for corridor and rock.
        $owner = array_fill(0, $n, null);
        foreach ($rooms as $room) {
            for ($y = $room['gy']; $y < $room['gy'] + $room['h']; $y++) {
                for ($x = $room['gx']; $x < $room['gx'] + $room['w']; $x++) {
                    if ($x >= 0 && $y >= 0 && $x < $cols && $y < $rows) {
                        $owner[$y * $cols + $x] = (int) $room['id'];
                    }
                }
            }
        }

        $pairs = [];
        $add = static function (int $a, int $b) use (&$pairs): void {
            if ($a === $b) {
                return;
            }
            $key = min($a, $b) . ':' . max($a, $b);
            $pairs[$key] = ['a' => min($a, $b), 'b' => max($a, $b), 'door' => 'door'];
        };

        // Rooms sharing a wall need no corridor between them.
        foreach ($rooms as $room) {
            $id = (int) $room['id'];
            for ($y = $room['gy'] - 1; $y <= $room['gy'] + $room['h']; $y++) {
                for ($x = $room['gx'] - 1; $x <= $room['gx'] + $room['w']; $x++) {
                    if ($x < 0 || $y < 0 || $x >= $cols || $y >= $rows) {
                        continue;
                    }
                    $other = $owner[$y * $cols + $x];
                    if ($other !== null && $other !== $id) {
                        $add($id, $other);
                    }
                }
            }
        }

        // Corridor components, and the rooms each one reaches.
        $seen = array_fill(0, $n, false);
        for ($i = 0; $i < $n; $i++) {
            if ($seen[$i] || $owner[$i] !== null || empty($floor[$i])) {
                continue;
            }
            $touch = [];
            $stack = [$i];
            $seen[$i] = true;
            while ($stack !== []) {
                $t = array_pop($stack);
                $tx = $t % $cols;
                $ty = intdiv($t, $cols);
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $nx = $tx + $dx;
                    $ny = $ty + $dy;
                    if ($nx < 0 || $ny < 0 || $nx >= $cols || $ny >= $rows) {
                        continue;
                    }
                    $j = $ny * $cols + $nx;
                    if ($owner[$j] !== null) {
                        $touch[$owner[$j]] = true;
                        continue;
                    }
                    if ($seen[$j] || empty($floor[$j])) {
                        continue;
                    }
                    $seen[$j] = true;
                    $stack[] = $j;
                }
            }
            $ids = array_keys($touch);
            sort($ids);
            for ($k = 1; $k < count($ids); $k++) {
                $add($ids[$k - 1], $ids[$k]);
            }
        }

        $edges = array_values($pairs);
        if ($edges === []) {
            return [];
        }

        // Anything the carving left stranded, joined to its nearest neighbour.
        //
        // A floor with an unreachable room is a floor with a location nobody
        // can walk to, which the containment check would pass and a player
        // would simply never see. Rare — the generator connects what it draws —
        // but "rare" is not a thing to leave to chance in a place that is
        // written into the world as rows.
        return self::joinStragglers($rooms, $edges);
    }

    /** @return list<array{a:int,b:int,door:string}> */
    private static function joinStragglers(array $rooms, array $edges): array
    {
        $linked = [];
        foreach ($edges as $e) {
            $linked[$e['a']][] = $e['b'];
            $linked[$e['b']][] = $e['a'];
        }

        $seen = [];
        $stack = [(int) $rooms[0]['id']];
        while ($stack !== []) {
            $at = array_pop($stack);
            if (isset($seen[$at])) {
                continue;
            }
            $seen[$at] = true;
            foreach ($linked[$at] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        foreach ($rooms as $room) {
            $id = (int) $room['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $best = null;
            $bestDist = PHP_INT_MAX;
            foreach ($rooms as $other) {
                $oid = (int) $other['id'];
                if (!isset($seen[$oid])) {
                    continue;
                }
                $dx = ($room['gx'] + $room['w'] / 2) - ($other['gx'] + $other['w'] / 2);
                $dy = ($room['gy'] + $room['h'] / 2) - ($other['gy'] + $other['h'] / 2);
                $d = (int) ($dx * $dx + $dy * $dy);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $oid;
                }
            }
            if ($best !== null) {
                $edges[] = ['a' => min($id, $best), 'b' => max($id, $best), 'door' => 'door'];
                $seen[$id] = true;
            }
        }

        return $edges;
    }

    /**
     * The generator's own words for its rooms.
     *
     * A note is one line — "Threshold. The lintel is scorched." — which is a
     * description rather than a name, so the first sentence becomes the name
     * and the whole of it stays as the description. A room the generator said
     * nothing about is left out and DungeonGen names it.
     *
     * @return array<int,array{name:string,description:string}>
     */
    private static function prose(array $dungeon): array
    {
        $out = [];
        foreach ($dungeon['lore']['notes'] ?? [] as $note) {
            $room = (int) ($note['room'] ?? -1);
            $text = trim((string) ($note['text'] ?? ''));
            if ($room < 0 || $text === '') {
                continue;
            }
            $stop = strcspn($text, '.!?');
            $name = trim(substr($text, 0, $stop));
            $out[$room] = [
                'name' => $name === '' ? '' : $name,
                'description' => $text,
            ];
        }
        return $out;
    }
}
