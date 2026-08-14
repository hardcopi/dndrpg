<?php
/**
 * A dungeon drawn by hand, in the shape DungeonGen already rolls.
 *
 * Rooms are rectangles on the generator's coarse plan. Halls are pairs of
 * rooms. This class does not invent a fourth grid: it builds the same $level
 * generate() returns, then plan() and tiles() do what they already do. The
 * chart, the walker and the book see one geometry.
 *
 * PURE STATIC, no PDO — same reason DungeonGen is. ContentEditor writes the
 * rows; this only checks the drawing and compiles it.
 */

declare(strict_types=1);

final class PlanAuthored
{
    /**
     * Normalise and refuse a broken drawing.
     *
     * Rooms must sit on the plan, keep a cell of clearance (or routes() has
     * nowhere to run), and carry unique keys. Halls must name two rooms that
     * exist. A plan with no rooms is not a dungeon.
     *
     * @param  array<string,mixed> $plan
     * @return array{rooms: list<array<string,mixed>>, halls: list<array<string,mixed>>}
     */
    public static function normalize(array $plan): array
    {
        $roomsIn = $plan['rooms'] ?? null;
        if (!is_array($roomsIn) || $roomsIn === []) {
            throw new InvalidArgumentException('A dungeon plan needs at least one room.');
        }

        $rooms = [];
        $byId = [];
        $keys = [];
        foreach ($roomsIn as $i => $r) {
            if (!is_array($r)) {
                throw new InvalidArgumentException('Every room is an object.');
            }
            $id = isset($r['id']) ? (int) $r['id'] : $i;
            $gx = (int) ($r['gx'] ?? -1);
            $gy = (int) ($r['gy'] ?? -1);
            $w = max(1, (int) ($r['w'] ?? 1));
            $h = max(1, (int) ($r['h'] ?? 1));
            if ($gx < 0 || $gy < 0 || $gx + $w > DungeonGen::GRID_W || $gy + $h > DungeonGen::GRID_H) {
                throw new InvalidArgumentException(
                    "Room {$id} sits off the plan ({$gx},{$gy} {$w}×{$h})."
                );
            }
            if (!DungeonGen::fits($rooms, $gx, $gy, $w, $h)) {
                throw new InvalidArgumentException(
                    "Room {$id} overlaps another, or leaves no cell for a hall."
                );
            }
            $key = trim((string) ($r['key'] ?? ''));
            if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
                throw new InvalidArgumentException("Room {$id} needs a location key.");
            }
            if (isset($keys[$key])) {
                throw new InvalidArgumentException("Two rooms share the key {$key}.");
            }
            $keys[$key] = true;
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                $name = 'A room';
            }
            $row = [
                'id'   => $id,
                'key'  => $key,
                'name' => $name,
                'gx'   => $gx,
                'gy'   => $gy,
                'w'    => $w,
                'h'    => $h,
            ];
            $rooms[] = $row;
            $byId[$id] = $row;
        }

        $halls = [];
        foreach ($plan['halls'] ?? [] as $i => $h) {
            if (!is_array($h)) {
                throw new InvalidArgumentException('Every hall is an object.');
            }
            $a = (int) ($h['a'] ?? -1);
            $b = (int) ($h['b'] ?? -1);
            if (!isset($byId[$a], $byId[$b]) || $a === $b) {
                throw new InvalidArgumentException("Hall {$i} does not join two rooms.");
            }
            $pair = min($a, $b) . ':' . max($a, $b);
            if (isset($halls[$pair])) {
                continue;
            }
            $door = (string) ($h['door'] ?? 'open');
            if (!in_array($door, ['open', 'arch', 'door', 'stuck', 'locked', 'portcullis'], true)) {
                $door = 'open';
            }
            $halls[$pair] = [
                'id'     => isset($h['id']) ? (int) $h['id'] : $i,
                'a'      => $a,
                'b'      => $b,
                'door'   => $door,
                'hidden' => !empty($h['hidden']),
            ];
        }

        return ['rooms' => $rooms, 'halls' => array_values($halls)];
    }

    /**
     * The $level plan() and tiles() already know how to consume.
     *
     * @param  array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public static function level(array $plan): array
    {
        $plan = self::normalize($plan);
        $rooms = [];
        foreach ($plan['rooms'] as $i => $r) {
            [$x, $y] = DungeonGen::project($r['gx'] + $r['w'] / 2, $r['gy'] + $r['h'] / 2);
            $rooms[] = [
                'id'          => (int) $r['id'],
                'gx'          => (int) $r['gx'],
                'gy'          => (int) $r['gy'],
                'w'           => (int) $r['w'],
                'h'           => (int) $r['h'],
                'x'           => $x,
                'y'           => $y,
                'kind'        => 'empty',
                'role'        => $i === 0 ? 'entrance' : 'room',
                'name'        => $r['name'],
                'description' => '',
                'key'         => $r['key'],
            ];
        }
        $corridors = [];
        foreach ($plan['halls'] as $i => $h) {
            $corridors[] = [
                'id'          => (int) $h['id'],
                'a'           => (int) $h['a'],
                'b'           => (int) $h['b'],
                'door'        => (string) $h['door'],
                'name'        => 'A passage',
                'description' => '',
                'hidden'      => !empty($h['hidden']),
            ];
        }
        return [
            'rooms'     => $rooms,
            'edges'     => $corridors,
            'corridors' => $corridors,
            'entrance'  => $rooms[0]['id'],
            'stair'     => $rooms[0]['id'],
        ];
    }

    /**
     * Chart geometry and the first-person raster, from one drawing.
     *
     * @return array{level: array, plan: array, tiles: array, dropped: int}
     */
    public static function compile(array $plan): array
    {
        $level = self::level($plan);
        $drawn = DungeonGen::plan($level);
        $routes = DungeonGen::routes($level);
        $dropped = 0;
        foreach ($level['corridors'] as $c) {
            if (!isset($routes[(int) $c['id']])) {
                $dropped++;
            }
        }
        return [
            'level'   => $level,
            'plan'    => $drawn,
            'tiles'   => DungeonGen::tiles($level),
            'dropped' => $dropped,
        ];
    }
}
