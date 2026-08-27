<?php
/**
 * Generated dungeon levels: are they places, and can they be walked?
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_dungeon.php`
 * — or with plain `php` anywhere, because DungeonGen touches no database. That
 * is the whole reason it is pure: this file generates well over a thousand
 * levels and checks every one of them, and it costs milliseconds.
 *
 * The sweep is modelled on the one tools/test_combat.php runs over
 * BattleMapGen, and asserts the same class of thing: not that a particular
 * dungeon is good, which is a matter of taste, but that no dungeon is BROKEN —
 * no unreachable room, no stair you cannot get to, no two rooms in the same
 * place, no node drawn off the edge of the chart.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/DungeonGen.php';

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        echo "  ok   $n\n";
    } else {
        $fail++;
        echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n";
    }
}
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}
/** The two rooms whose names are fixed rather than drawn from the table. */
function same_quiet(array &$bad, array $level, string $role, string $want): void
{
    $id = $role === 'entrance' ? $level['entrance'] : $level['stair'];
    if (($level['rooms'][$id]['name'] ?? '') !== $want) {
        $bad[] = "seed {$level['seed']}: the {$role} is not called {$want}";
    }
}

function section(string $t): void
{
    echo PHP_EOL . '== ' . $t . ' ==' . PHP_EOL;
}

section('the same seed builds the same dungeon');
$a = DungeonGen::generate(12345, 2);
$b = DungeonGen::generate(12345, 2);
same('rooms', $a['rooms'], $b['rooms']);
same('and ways', $a['edges'], $b['edges']);
same('and the dressing', $a['corridors'], $b['corridors']);
same('and the atmosphere', $a['atmosphere'], $b['atmosphere']);
ok('a different seed builds a different one',
    DungeonGen::generate(999, 2)['rooms'] !== $a['rooms']);
ok('and so does a different depth',
    DungeonGen::generate(12345, 3)['rooms'] !== $a['rooms']);

section('the main rng stream is frozen');
// Recorded 2026-08-07, BEFORE the dressing pass existed. This pins the fields
// the MAIN rng stream owns — geometry, kinds, roles, names, edge pairs, and
// the base door classes — for one known seed. The dressing pass (doors
// upgraded to locked/portcullis/trapped, traps, atmosphere appended to prose)
// draws on its own derived stream precisely so this hash never moves: every
// existing delve is a seed on disk, and a layout that regenerates differently
// is a save that quietly rots. If this fails, some new roll was fed into the
// main stream — move it to the dressing stream instead of re-recording the
// hash. Descriptions are excluded (dress() appends to some) and upgraded door
// types are collapsed back to 'door' (they are dress()-time refinements OF
// 'door'); everything else must match to the byte.
$frozen = DungeonGen::generate(4471, 2);
// `furnishing` and `dressing` are excluded for exactly the reason `description` is: dress()
// adds it, on the dressing stream, below every draw that existed before it. The
// layout, kinds, roles, names and edges this hash exists to freeze are
// byte-identical with it stripped — checked, not assumed, when it was added.
// Excluding a field is only ever right when that is true; a hash that moves
// because the GEOMETRY moved must be paid for the way the two re-recordings
// below were, not waved through by widening this unset().
$frozenRooms = array_map(
    function ($r) { unset($r['description'], $r['furnishing'], $r['dressing']); return $r; },
    $frozen['rooms']
);
$frozenDoors = array_map(
    fn ($e) => [$e['a'], $e['b'], in_array($e['door'], ['open', 'arch', 'stuck'], true) ? $e['door'] : 'door'],
    $frozen['edges']
);
// RE-RECORDED 2026-08-08, deliberately: the room-slot plan went from eleven by
// eight to nine by seven so the rooms are drawn at a size worth reading, which
// moves every layout by construction. That is the save-rot this hash exists to
// catch, so it was paid for the way the last one was — tools/end_open_delves.php
// was run at deploy, every party climbed out, and the next descent is built
// whole by the new code. Re-record it again ONLY for a change that has been
// paid for the same way; a hash that moves on its own is still a bug.
same('generate(4471, 2) is the level it was in 2026',
    'b58b8fc38c0ec9c129b72c53de9850bbbed4c8979fa7b63ba2b051c200898098',
    hash('sha256', json_encode([
        $frozenRooms, $frozenDoors,
        array_column($frozen['corridors'], 'name'),
        $frozen['entrance'], $frozen['stair'],
    ])));

section('depth picks the profile');
same('the first level is the upper one', 'upper', DungeonGen::profileForDepth(1));
same('the second is the middle', 'middle', DungeonGen::profileForDepth(2));
same('the third is deep', 'deep', DungeonGen::profileForDepth(3));
same('and so is everything below it', 'deep', DungeonGen::profileForDepth(9));
same('depth zero is still a dungeon', 'upper', DungeonGen::profileForDepth(0));

// -------------------------------------------------------------------------
// The sweep. Every profile, hundreds of seeds, every level checked whole.
// -------------------------------------------------------------------------
section('every level is a place you can walk');

$bad = [];
$roomTotals = [];
$kinds = [];
$doors = [];
$SEEDS = 400;

foreach ([1, 2, 3] as $depth) {
    $profile = DungeonGen::profileForDepth($depth);
    [$minRooms, $maxRooms] = DungeonGen::PROFILES[$profile]['rooms'];

    for ($i = 0; $i < $SEEDS; $i++) {
        $seed = $i * 7919 + $depth;
        $level = DungeonGen::generate($seed, $depth);
        $where = "{$profile}/{$seed}";
        $rooms = $level['rooms'];
        $n = count($rooms);
        $roomTotals[] = $n;

        // 1. Walkable: every room reachable from the entrance, stair among them.
        if (!DungeonGen::isWalkable($level)) {
            $bad[] = "{$where}: not walkable";
            continue;
        }

        // 2. A level with one room is not a level.
        if ($n < 2) {
            $bad[] = "{$where}: {$n} room(s)";
        }
        // The generator gives up quietly rather than stalling, so the floor is
        // what matters and the ceiling is a promise it must not break.
        if ($n > $maxRooms) {
            $bad[] = "{$where}: {$n} rooms, over the profile's {$maxRooms}";
        }

        // 3. You come in somewhere and you go down somewhere else.
        if ($level['entrance'] === $level['stair']) {
            $bad[] = "{$where}: in and down are the same room";
        }
        if ($rooms[$level['entrance']]['role'] !== 'entrance'
            || $rooms[$level['stair']]['role'] !== 'stair') {
            $bad[] = "{$where}: roles do not match the named rooms";
        }
        if ($rooms[$level['entrance']]['kind'] !== 'empty') {
            $bad[] = "{$where}: the entrance holds something";
        }
        if ($rooms[$level['stair']]['kind'] === 'empty') {
            $bad[] = "{$where}: the way down is unguarded";
        }

        // 3b. And you can walk out of the room you come in at without
        //     searching first. `isWalkable()` reads the graph and not the
        //     doors, so a level whose entrance was ringed by stuck doors —
        //     hidden until found, on the room end of the join — passed every
        //     check above while being, to the party standing in it, a floor
        //     plan full of rooms that all answer "there is no way to get there
        //     from here".
        $ways = 0;
        $open = 0;
        foreach ($level['edges'] as $e) {
            if ($e['a'] === $level['entrance'] || $e['b'] === $level['entrance']) {
                $ways++;
                $open += $e['door'] === 'stuck' ? 0 : 1;
            }
        }
        if ($ways === 0) {
            $bad[] = "{$where}: nothing leads out of the entrance";
        } elseif ($open === 0) {
            $bad[] = "{$where}: every way out of the entrance is a jammed door";
        }

        // 4. No two rooms in the same place, on the coarse plan.
        foreach ($rooms as $p) {
            foreach ($rooms as $q) {
                if ($p['id'] >= $q['id']) {
                    continue;
                }
                if ($p['gx'] < $q['gx'] + $q['w'] && $q['gx'] < $p['gx'] + $p['w']
                    && $p['gy'] < $q['gy'] + $q['h'] && $q['gy'] < $p['gy'] + $p['h']) {
                    $bad[] = "{$where}: rooms {$p['id']} and {$q['id']} overlap";
                }
            }
        }

        // 5. Every node inside the field ui-map.js draws in, with room for the
        //    name written under it. load_content.py warns above y = 80 for
        //    exactly this reason; nothing generated should ever get near it.
        foreach ($rooms as $r) {
            if ($r['x'] < 0 || $r['x'] > DungeonGen::VIEW_W
                || $r['y'] < 0 || $r['y'] > DungeonGen::VIEW_H) {
                $bad[] = "{$where}: room {$r['id']} at {$r['x']},{$r['y']} is off the chart";
            }
            $kinds[$r['kind']] = ($kinds[$r['kind']] ?? 0) + 1;
        }

        // 6. Edges name real rooms, and nothing is joined to itself.
        foreach ($level['edges'] as $e) {
            if (!isset($rooms[$e['a']]) || !isset($rooms[$e['b']])) {
                $bad[] = "{$where}: a way leads nowhere";
            }
            if ($e['a'] === $e['b']) {
                $bad[] = "{$where}: room {$e['a']} joins itself";
            }
            $doors[$e['door']] = ($doors[$e['door']] ?? 0) + 1;
        }

        // 6b. The dressing holds its own promises: the door on an edge and on
        //     its corridor are one fact stated twice; a level is never more
        //     toll-booth than dungeon; every trap is complete enough to fire;
        //     and the level knows what it is like.
        $trapCount = 0;
        foreach ($level['corridors'] as $ci => $c) {
            if ($c['door'] !== $level['edges'][$ci]['door']) {
                $bad[] = "{$where}: corridor {$ci} disagrees with its edge about the door";
            }
            if (isset($c['trap'])) {
                $trapCount++;
                foreach (['key', 'name', 'save', 'dc', 'damage', 'text', 'found_text'] as $k) {
                    if (empty($c['trap'][$k])) {
                        $bad[] = "{$where}: trap on corridor {$ci} is missing {$k}";
                    }
                }
                if ($c['trap']['dc'] < 1 || $c['trap']['dc'] > 30) {
                    $bad[] = "{$where}: trap DC {$c['trap']['dc']} is outside CheckService's 1..30";
                }
                if (!preg_match('/^\d+d\d+([+-]\d+)?$/', $c['trap']['damage'])) {
                    $bad[] = "{$where}: trap damage '{$c['trap']['damage']}' is not dice";
                }
                if (!in_array($c['trap']['save'], ['dex', 'con'], true)) {
                    $bad[] = "{$where}: trap saves against '{$c['trap']['save']}'";
                }
            }
        }
        if ($trapCount > DungeonGen::TRAP_CAP) {
            $bad[] = "{$where}: {$trapCount} rigged passages, over the cap";
        }
        if (empty($level['atmosphere']['label']) || empty($level['atmosphere']['air'])
            || empty($level['atmosphere']['light'])) {
            $bad[] = "{$where}: the level has no atmosphere";
        }

        // 6c. The entrance guard survives the dressing: at least one way out of
        //     the arrival room that is not hidden (stuck), not shut (locked or
        //     portcullis), and not rigged (trapped) — the mandatory first step
        //     of a delve is a step, not a toll.
        $freeOut = 0;
        foreach ($level['edges'] as $ei => $e) {
            if (($e['a'] === $level['entrance'] || $e['b'] === $level['entrance'])
                && in_array($e['door'], ['open', 'arch', 'door'], true)
                && !isset($level['corridors'][$ei]['trap'])) {
                $freeOut++;
            }
        }
        if ($freeOut === 0) {
            $bad[] = "{$where}: every way out of the entrance hides, blocks or bites";
        }

        // 7. Deep levels end on something worth the trip; shallow ones do not.
        $hasBoss = false;
        foreach ($rooms as $r) {
            if ($r['kind'] === 'boss') {
                $hasBoss = true;
            }
        }
        if ($depth >= 3 && !$hasBoss) {
            $bad[] = "{$where}: a deep level with nothing at the bottom";
        }
        if ($depth < 3 && $hasBoss) {
            $bad[] = "{$where}: a boss above the deep levels";
        }
    }
}

$swept = $SEEDS * 3;
same("{$swept} generated levels are all sound", [], array_slice($bad, 0, 6));

section('what the sweep produced');
sort($roomTotals);
printf(
    "  rooms per level: %d smallest, %d largest, %.1f mean%s",
    $roomTotals[0],
    $roomTotals[count($roomTotals) - 1],
    array_sum($roomTotals) / count($roomTotals),
    PHP_EOL
);
$totalRooms = array_sum($kinds);
ksort($kinds);
foreach ($kinds as $kind => $n) {
    printf("  %-9s %5d  %4.1f%%%s", $kind, $n, 100 * $n / $totalRooms, PHP_EOL);
}
ksort($doors);
echo '  doors: ';
$parts = [];
foreach ($doors as $door => $n) {
    $parts[] = "{$door} {$n}";
}
echo implode(', ', $parts) . PHP_EOL;

// The dressing rates, banded like the stocking shares and for the same
// reason: the exact weights are taste, but a dungeon where a fifth of the
// doors are shut in your face is a queue, and one with none is a dungeon
// that never asks the strong character for anything. Re-derived from the
// sweep above (1200 levels), not invented: at the shipped weights locked
// runs ~2.9% and portcullis ~1.4% of ALL doorways.
$totalDoors = array_sum($doors);
$shutShare = (($doors['locked'] ?? 0) + ($doors['portcullis'] ?? 0)) / $totalDoors;
$riggedShare = ($doors['trapped'] ?? 0) / $totalDoors;
ok('a few doors are shut, and only a few', $shutShare > 0.01 && $shutShare <= 0.10,
    sprintf('%.1f%% are', $shutShare * 100));
ok('and fewer are rigged', $riggedShare > 0.005 && $riggedShare <= 0.08,
    sprintf('%.1f%% are', $riggedShare * 100));

// A levelling dungeon has to be worth delving. Asserted as a band rather than a
// number because the contents table is a matter of taste and will be retuned —
// what must not happen is a floor of nothing but empty rooms, or one where
// every door is a fight and the level is a slog.
$fightShare = (($kinds['monster'] ?? 0) + ($kinds['hoard'] ?? 0) + ($kinds['boss'] ?? 0)) / $totalRooms;
ok('roughly half of every floor is alive', $fightShare >= 0.40 && $fightShare <= 0.55,
    sprintf('%.1f%% is', $fightShare * 100));
ok('and a quarter is still empty',
    ($kinds['empty'] ?? 0) / $totalRooms >= 0.20,
    sprintf('%.1f%%', 100 * ($kinds['empty'] ?? 0) / $totalRooms));

// A floor worth delving. The band exists because the contents table is a
// matter of taste and will be retuned; what must not happen is a level that is
// a corridor of fights, or one worth so little that levelling here is slower
// than playing the authored game.
$aliveRooms = $fightShare * (array_sum($roomTotals) / count($roomTotals));
ok('a level is worth about five fights', $aliveRooms >= 4.0 && $aliveRooms <= 7.0,
    sprintf('%.1f', $aliveRooms));

// Every room is a NAME on the region chart, and ui-map.js gives a known node a
// hit box twenty units wide in a hundred-unit field. Two nodes closer than
// this write over each other. The chart zooms to 6x so some crowding is
// navigable rather than broken — but it must not get worse without somebody
// deciding that it should.
$closest = 999.0;
foreach ([1, 2, 3] as $d) {
    for ($i = 0; $i < 120; $i++) {
        $rs = DungeonGen::generate($i * 15485863 + $d, $d)['rooms'];
        foreach ($rs as $p) {
            foreach ($rs as $q) {
                if ($p['id'] >= $q['id']) {
                    continue;
                }
                $gap = sqrt(($p['x'] - $q['x']) ** 2 + ($p['y'] - $q['y']) ** 2);
                $closest = min($closest, $gap);
            }
        }
    }
}
ok('no two rooms are lettered on top of each other', $closest >= 12.0,
    sprintf('closest pair %.1f units apart', $closest));

section('the prose');
// Every room is a screen, so none may be blank, and no level may say the same
// thing twice in a row — the one repeat a player actually notices.
$proseBad = [];
$backToBack = 0;
$roomsSeen = 0;
for ($i = 0; $i < 300; $i++) {
    $depth = ($i % 3) + 1;
    $level = DungeonGen::generate($i * 15485863, $depth);
    $prev = null;
    foreach ($level['rooms'] as $r) {
        $roomsSeen++;
        if (trim($r['name']) === '') {
            $proseBad[] = "seed {$level['seed']} room {$r['id']}: no name";
        }
        if (strlen($r['description']) < 60) {
            $proseBad[] = "seed {$level['seed']} room {$r['id']}: a stub description";
        }
        // Four clauses and a hint: at least five sentences worth of full stops.
        if (substr_count($r['description'], '.') < 4) {
            $proseBad[] = "seed {$level['seed']} room {$r['id']}: too few clauses";
        }
        if ($prev !== null && $prev === $r['description']) {
            $backToBack++;
        }
        $prev = $r['description'];
    }
    // The two rooms that are named for what they are, not from the table.
    same_quiet($proseBad, $level, 'entrance', 'The Way In');
    same_quiet($proseBad, $level, 'stair', 'The Way Down');
}
same('every generated room has prose', [], array_slice($proseBad, 0, 5));
same('and no two rooms in a row read identically', 0, $backToBack);
printf('  %d rooms described%s', $roomsSeen, PHP_EOL);

// The hint is the only clause that refers to what is in the room, and it must
// never COUNT anything: a description saying "four goblins" is wrong the moment
// one of them dies, and the people list beside it is drawn from real rows.
//
// Checked against the hint table itself rather than against finished prose.
// The first version of this scanned whole descriptions for number words and
// flagged 570 rooms — every "one corner", "one wall", "one of them has split".
// Those are English, not a head count, and the assertion was the thing at fault.
$counting = [];
foreach (DungeonGen::PROSE_KIND as $kind => $hints) {
    foreach ($hints as $hint) {
        if (preg_match('/\b(one|two|three|four|five|six|seven|eight|nine|ten|\d+)\b/i', $hint)) {
            $counting[] = "{$kind}: {$hint}";
        }
    }
}
same('no contents hint counts what is standing in the room', [], $counting);

// And every kind the stocking table can produce has something to say about
// itself, or a room would arrive with three clauses and a full stop.
$missing = [];
foreach (DungeonGen::CONTENTS as $c) {
    if (empty(DungeonGen::PROSE_KIND[$c['kind']])) {
        $missing[] = $c['kind'];
    }
}
if (empty(DungeonGen::PROSE_KIND['boss'])) {
    $missing[] = 'boss';
}
same('every room kind has a hint written for it', [], $missing);

section('loops');
// A pure tree makes every wrong turn a dead end. Deeper levels are meant to
// tangle: more ways than rooms-minus-one means at least one way round.
$treeish = 0;
$looped = 0;
for ($i = 0; $i < 200; $i++) {
    $deep = DungeonGen::generate($i * 104729, 3);
    $extra = count($deep['edges']) - (count($deep['rooms']) - 1);
    if ($extra > 0) {
        $looped++;
    } else {
        $treeish++;
    }
}
ok('deep levels nearly always have a way round', $looped > 180, "{$looped} of 200 did");

section('room names are identifiers, not prose');
// A name is the click target on the chart and the only label a room shows.
// Two rooms with one name is two identical destinations on the same map. This
// held for 63% of levels while NAMES had ten entries against profiles asking
// for up to fourteen rooms: the deck ran out and reshuffled mid-level.
$repeated = 0;
$checked = 0;
for ($i = 1; $i <= 400; $i++) {
    for ($d = 1; $d <= 5; $d++) {
        $level = DungeonGen::generate($i * 7919, $d);
        $checked++;
        $names = array_column($level['rooms'], 'name');
        if (count($names) !== count(array_unique($names))) {
            $repeated++;
        }
    }
}
ok('no level repeats a room name', $repeated === 0, "{$repeated} of {$checked} did");

// The guard above passes for the wrong reason if the tables merely happen to
// be long enough for the room counts in use today. Assert the margin itself,
// so raising a profile's room count past its name table fails HERE rather
// than as duplicate names on somebody's map.
$short = [];
foreach (DungeonGen::PROFILES as $profile => $spec) {
    $have = count(DungeonGen::NAMES[$profile][0]);
    if ($have <= $spec['rooms'][1]) {
        $short[] = "{$profile}: {$have} names for up to {$spec['rooms'][1]} rooms";
    }
}
ok('every name table outlasts its profile\'s largest level',
    $short === [], implode('; ', $short));

section('set dressing');
// Every named chamber promises something you can see. The kind is the name,
// so this is a table check, not a roll check — a Guard Room without bunks
// is a name that lied. The Way In and the Way Down stay bare on purpose:
// the spine is where a party is put down.

$unnamed = [];
foreach (DungeonGen::NAMES as $decks) {
    foreach ($decks[0] as $name) {
        if (!isset(DungeonGen::DRESSING_FOR[$name])) {
            $unnamed[] = $name;
        }
    }
}
same('every room name has a dressing kind', [], $unnamed);

$unknownKind = [];
foreach (DungeonGen::DRESSING_FOR as $name => $kind) {
    if (!isset(DungeonGen::DRESSING[$kind])) {
        $unknownKind[] = "{$name} => {$kind}";
    }
}
same('every dressing kind is in DRESSING', [], $unknownKind);

$lootKinds = [];
foreach (DungeonGen::FURNISHINGS as $spec) {
    $lootKinds[$spec['kind']] = true;
}

$bare = [];
$mismatch = [];
$noClause = [];
$sawGuard = false;
for ($i = 1; $i <= 80; $i++) {
    for ($d = 1; $d <= 5; $d++) {
        $level = DungeonGen::generate($i * 7919, $d);
        $planById = [];
        foreach (DungeonGen::plan($level)['rooms'] as $pr) {
            $planById[(int) $pr['id']] = $pr;
        }
        $byRoom = [];
        foreach (DungeonGen::tiles($level)['props'] as $prop) {
            $byRoom[(int) $prop['room']][] = $prop;
        }
        foreach ($level['rooms'] as $r) {
            $role = (string) ($r['role'] ?? '');
            if ($role === 'entrance' || $role === 'stair') {
                if (!empty($r['dressing'])) {
                    $bare[] = "{$r['name']} is a {$role} with dressing";
                }
                continue;
            }
            $want = DungeonGen::DRESSING_FOR[$r['name']] ?? 'rubble';
            $got = $r['dressing']['kind'] ?? null;
            if ($got !== $want) {
                $mismatch[] = "{$r['name']}: got " . ($got ?? 'none');
            }
            $clause = DungeonGen::DRESSING[$want]['clause'];
            if (!str_contains((string) ($r['description'] ?? ''), $clause)) {
                $noClause[] = $r['name'];
            }
            $planKind = $planById[(int) $r['id']]['dressing'] ?? null;
            if ($planKind !== $want) {
                $mismatch[] = "plan {$r['name']}: " . ($planKind ?? 'none');
            }
            $props = $byRoom[(int) $r['id']] ?? [];
            $kinds = array_column($props, 'kind');
            if (!in_array($want, $kinds, true)) {
                $hasLoot = false;
                foreach ($props as $prop) {
                    if (!empty($prop['loot'])) {
                        $hasLoot = true;
                    }
                }
                // One-tile room with a chest: dressing is withheld so they
                // do not share a cell. Anything else is a missed prop.
                if (!$hasLoot || count($props) !== 1) {
                    $bare[] = "tiles missing {$want} in {$r['name']}";
                }
            }
            if ($r['name'] === 'The Guard Room') {
                $sawGuard = true;
                if (!str_contains((string) $r['description'], 'Bunks line the wall')) {
                    $noClause[] = 'The Guard Room (no bunks clause)';
                }
            }
            foreach ($props as $prop) {
                $kind = (string) $prop['kind'];
                if (!empty($prop['loot']) && isset(DungeonGen::DRESSING[$kind])) {
                    $bare[] = "loot flag on dressing {$kind} in {$r['name']}";
                }
                if (empty($prop['loot']) && isset($lootKinds[$kind])) {
                    $bare[] = "chest without loot flag in {$r['name']}";
                }
            }
        }
    }
}
same('non-stair rooms carry their dressing', [], array_slice($bare, 0, 5));
same('dressing kind matches the name table', [], array_slice($mismatch, 0, 5));
same('the clause is on the paragraph', [], array_slice($noClause, 0, 5));
ok('the sample saw a Guard Room', $sawGuard);

section('passages are places');
// A corridor used to be a line drawn between two rooms. It is a location now:
// DelveEngine writes one per edge and wires room -> passage -> room, so a
// hallway is somewhere the party stands rather than a thread on a diagram.
$pRepeats = 0;
$pMissing = 0;
$pChecked = 0;
for ($i = 1; $i <= 400; $i++) {
    for ($d = 1; $d <= 5; $d++) {
        $level = DungeonGen::generate($i * 7919, $d);
        $pChecked++;
        // One passage per edge, in the same order, so corridors[$n] and
        // edges[$n] are the same join described twice.
        if (count($level['corridors']) !== count($level['edges'])) {
            $pMissing++;
            continue;
        }
        foreach ($level['corridors'] as $n => $c) {
            if ((int) $c['a'] !== (int) $level['edges'][$n]['a']
                || (int) $c['b'] !== (int) $level['edges'][$n]['b']
                || $c['name'] === '' || $c['description'] === '') {
                $pMissing++;
                break;
            }
        }
        $pNames = array_column($level['corridors'], 'name');
        if (count($pNames) !== count(array_unique($pNames))) {
            $pRepeats++;
        }
    }
}
ok('every edge has a passage, named and described',
    $pMissing === 0, "{$pMissing} of {$pChecked} levels did not");
// Nothing on the chart is clickable by a passage's name — the run is the
// target — but the name is the scene heading and the travel log, and two
// identical ones in a journal read as a bug.
ok('no level repeats a passage name', $pRepeats === 0, "{$pRepeats} of {$pChecked} did");

$pShort = [];
foreach (DungeonGen::PROFILES as $profile => $spec) {
    // The spanning tree is rooms - 1 edges, plus the loops.
    $mostEdges = $spec['rooms'][1] - 1 + $spec['loops'][1];
    $have = count(DungeonGen::PASSAGE_NAMES[$profile]);
    if ($have <= $mostEdges) {
        $pShort[] = "{$profile}: {$have} names for up to {$mostEdges} passages";
    }
}
ok('every passage-name table outlasts its profile\'s largest level',
    $pShort === [], implode('; ', $pShort));

// A passage's marker has to be somewhere the party could stand, which for a
// dogleg is not the average of its two ends — that point is usually in the
// corner the run cuts, outside the passage entirely.
$offRun = 0;
for ($i = 1; $i <= 200; $i++) {
    $level = DungeonGen::generate($i * 104729, ($i % 5) + 1);
    foreach (DungeonGen::plan($level)['corridors'] as $c) {
        [$mx, $my] = $c['mid'];
        $on = false;
        for ($n = 1; $n < count($c['points']); $n++) {
            [$x1, $y1] = $c['points'][$n - 1];
            [$x2, $y2] = $c['points'][$n];
            // Every segment is axis-aligned, so "on the run" is two ranges.
            if ($mx >= min($x1, $x2) - 0.05 && $mx <= max($x1, $x2) + 0.05
                && $my >= min($y1, $y2) - 0.05 && $my <= max($y1, $y2) + 0.05) {
                $on = true;
                break;
            }
        }
        if (!$on) {
            $offRun++;
        }
    }
}
ok('a passage is marked at a point on its own run', $offRun === 0, "{$offRun} were not");

// A passage through a room it does not join reads as a corridor running
// across somebody's chamber with no door at either end. It was tolerable
// while a corridor was a hairline; drawn as a walled run it is the first
// thing the eye finds. plan() picks whichever dogleg crosses less, which took
// this from 16% of levels to under 3%. The rest need real routing rather than
// a choice between two shapes, and are rare enough to leave.
$crossed = 0;
$levelsWith = 0;
$seen = 0;
for ($i = 1; $i <= 300; $i++) {
    for ($d = 1; $d <= 5; $d++) {
        $level = DungeonGen::generate($i * 7919, $d);
        $plan = DungeonGen::plan($level);
        $seen++;
        $hit = false;
        foreach ($plan['corridors'] as $c) {
            foreach ($plan['rooms'] as $r) {
                if ((int) $r['id'] === (int) $c['a'] || (int) $r['id'] === (int) $c['b']) {
                    continue;
                }
                for ($n = 1; $n < count($c['points']); $n++) {
                    [$x1, $y1] = $c['points'][$n - 1];
                    [$x2, $y2] = $c['points'][$n];
                    if (max($x1, $x2) > $r['x'] && min($x1, $x2) < $r['x'] + $r['w']
                        && max($y1, $y2) > $r['y'] && min($y1, $y2) < $r['y'] + $r['h']) {
                        $crossed++;
                        $hit = true;
                        break 3;
                    }
                }
            }
        }
        if ($hit) {
            $levelsWith++;
        }
    }
}
ok('almost no level has a passage cutting through an unrelated room',
    $levelsWith * 100 < $seen * 3, "{$levelsWith} of {$seen} levels do");

section('the drawn plan');
// plan() is what ui-map.js paints. It is a pure function of a generated level,
// which is what lets DelveEngine throw the layout away and rebuild it from the
// seed whenever the chart asks.
$level = DungeonGen::generate(4471, 2);
$plan = DungeonGen::plan($level);

ok('a rectangle per room', count($plan['rooms']) === count($level['rooms']),
    count($plan['rooms']) . ' vs ' . count($level['rooms']));
ok('a corridor per way', count($plan['corridors']) === count($level['edges']),
    count($plan['corridors']) . ' vs ' . count($level['edges']));

$same = DungeonGen::plan(DungeonGen::generate(4471, 2));
ok('and it is the same plan every time', $plan == $same);

// Everything inside the field the chart draws, with room for the label that
// sits over it. A rect off the edge is a room you can see the name of and not
// the walls.
$outside = 0;
$overlap = 0;
$crooked = 0;
$adrift = 0;
$thresholds = 0;
for ($i = 0; $i < 300; $i++) {
    $lv = DungeonGen::generate($i * 31337 + 5, ($i % 5) + 1);
    $pl = DungeonGen::plan($lv);

    foreach ($pl['rooms'] as $r) {
        if ($r['x'] < 0 || $r['y'] < 0
            || $r['x'] + $r['w'] > DungeonGen::VIEW_W
            || $r['y'] + $r['h'] > DungeonGen::VIEW_H
            || $r['w'] <= 0 || $r['h'] <= 0) {
            $outside++;
        }
    }
    // placeRooms keeps a clear plan cell between rooms, so the drawn
    // rectangles cannot touch either. If they ever do, the inset in plan()
    // and the clearance in fits() have drifted apart.
    foreach ($pl['rooms'] as $a) {
        foreach ($pl['rooms'] as $b) {
            if ($a['id'] >= $b['id']) {
                continue;
            }
            if ($a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w']
                && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h']) {
                $overlap++;
            }
        }
    }
    $rect = array_column($pl['rooms'], null, 'id');
    foreach ($pl['corridors'] as $c) {
        $pts = $c['points'];
        for ($k = 0; $k < count($pts) - 1; $k++) {
            // Axis-aligned: a passage that runs diagonally is not a passage.
            if (abs($pts[$k][0] - $pts[$k + 1][0]) > 1e-6
                && abs($pts[$k][1] - $pts[$k + 1][1]) > 1e-6) {
                $crooked++;
            }
        }
        // The two door anchors are the two clipped ends, verbatim: `at_a`
        // is where the doorway shows in room a, `at` in room b. The wall
        // checks below then cover them for free.
        if ($c['at_a'] !== $pts[0] || $c['at'] !== $pts[count($pts) - 1]) {
            $thresholds++;
        }
        // Both ends have to land ON the room they claim to join, or the
        // corridor is drawn floating and the plan reads as disconnected.
        foreach ([[$pts[0], $rect[$c['a']]], [$pts[count($pts) - 1], $rect[$c['b']]]] as [$p, $room]) {
            $onX = abs($p[0] - $room['x']) < 0.01 || abs($p[0] - ($room['x'] + $room['w'])) < 0.01;
            $onY = abs($p[1] - $room['y']) < 0.01 || abs($p[1] - ($room['y'] + $room['h'])) < 0.01;
            $inX = $p[0] >= $room['x'] - 0.01 && $p[0] <= $room['x'] + $room['w'] + 0.01;
            $inY = $p[1] >= $room['y'] - 0.01 && $p[1] <= $room['y'] + $room['h'] + 0.01;
            if (!(($onX && $inY) || ($onY && $inX))) {
                $adrift++;
            }
        }
    }
}
ok('every room is drawn inside the chart', $outside === 0, "{$outside} were not");
ok('no two rooms are drawn over each other', $overlap === 0, "{$overlap} pairs did");
ok('every corridor runs square', $crooked === 0, "{$crooked} segments did not");
ok('and meets the wall of both rooms it joins', $adrift === 0, "{$adrift} ends did not");
ok('a doorway anchors on both thresholds', $thresholds === 0, "{$thresholds} did not");

// A 1x1 room and a 2x1 room should differ on the plan roughly as they differ
// in cells. The inset is subtracted from BOTH sides, so at a third of a cell
// it flattened every single-cell dimension into a slot: a 2x1 room came out
// four times as wide as tall.
$worst = 0.0;
for ($i = 0; $i < 200; $i++) {
    $lv = DungeonGen::generate($i * 7717 + 3, ($i % 5) + 1);
    $pl = DungeonGen::plan($lv);
    $rect = array_column($pl['rooms'], null, 'id');
    foreach ($lv['rooms'] as $r) {
        $drawn = $rect[$r['id']]['w'] / $rect[$r['id']]['h'];
        $cells = ($r['w'] * (DungeonGen::VIEW_W - 16) / DungeonGen::GRID_W)
               / ($r['h'] * (DungeonGen::VIEW_H - 16) / DungeonGen::GRID_H);
        $worst = max($worst, abs($drawn - $cells) / $cells);
    }
}
ok('a room is drawn in something like its own proportions', $worst < 0.2,
    sprintf('worst was %.0f%% off', $worst * 100));

// =========================================================================
section('the raster');
// tiles() is what ui-firstperson.js walks: the same level cut into ten-foot
// tiles, so a wall can be drawn where there is a wall. Everything below is
// one question asked several ways — DOES THE RASTER AGREE WITH THE GRAPH? A
// floor the first-person view can walk and the chart cannot, or the other way
// about, is two maps of one place disagreeing, and it would show as a party
// walking at an opening the server refuses to let them through.

/** Which locations a flood fill of the tiles can reach from a starting tile. */
function flood(array $t, int $from): array
{
    $doors = [];
    foreach ($t['doors'] as $d) {
        $doors[$d['tile'] * 4 + $d['dir']] = true;
    }
    $walls = [];
    foreach ($t['walls'] as $w) {
        $walls[$w['tile'] * 4 + $w['dir']] = true;
    }
    $steps = [-$t['w'], 1, $t['w'], -1];

    $seen = [$from => true];
    $queue = [$from];
    $owners = [];
    while ($queue) {
        $at = array_shift($queue);
        $o = $t['owner'][$at] ?? null;
        if ($o !== null) {
            $owners[$o[0] . ':' . $o[1]] = true;
        }
        foreach ($steps as $dir => $off) {
            $to = $at + $off;
            if (!isset($t['owner'][$to]) || $t['owner'][$to] === null) {
                continue;                                  // rock
            }
            if (isset($walls[$at * 4 + $dir])) {
                continue;                                  // a thin partition
            }
            $sameOwner = $t['owner'][$to] === $o;
            if (!$sameOwner && !isset($doors[$at * 4 + $dir])) {
                continue;   // two places with no doorway between them
            }
            if (!isset($seen[$to])) {
                $seen[$to] = true;
                $queue[] = $to;
            }
        }
    }
    return $owners;
}

$level = DungeonGen::generate(4471, 2);
same('the same seed carves the same tiles',
    DungeonGen::tiles($level), DungeonGen::tiles(DungeonGen::generate(4471, 2)));

$t = DungeonGen::tiles($level);
same('the grid is the plan sub-divided, plus a border',
    [DungeonGen::GRID_W * DungeonGen::SUB + 2, DungeonGen::GRID_H * DungeonGen::SUB + 2],
    [$t['w'], $t['h']]);

$bad = 0;
$shared = 0;
$border = 0;
$notRect = 0;
$abut = 0;
$badDoor = 0;
$oneSided = 0;
$wide = 0;
$partitions = 0;
$partitionLevels = 0;
$dropped = 0;
$cutOff = 0;
$mismatch = 0;
$levels = 0;
$ways = 0;

for ($i = 1; $i <= 400; $i++) {
    for ($d = 1; $d <= 5; $d++) {
        $lv = DungeonGen::generate($i * 7919, $d);
        $t = DungeonGen::tiles($lv);
        $routes = DungeonGen::routes($lv);
        $levels++;
        $shared += $t['shared'];

        // The border is the rock the level is cut from, and the reason nothing
        // downstream needs a bounds test.
        for ($x = 0; $x < $t['w']; $x++) {
            if ($t['owner'][$x] !== null || $t['owner'][($t['h'] - 1) * $t['w'] + $x] !== null) {
                $border++;
            }
        }
        for ($y = 0; $y < $t['h']; $y++) {
            if ($t['owner'][$y * $t['w']] !== null || $t['owner'][$y * $t['w'] + $t['w'] - 1] !== null) {
                $border++;
            }
        }

        // A room's tiles are its footprint exactly — the same rectangle plan()
        // draws, at SUB times the resolution.
        foreach ($lv['rooms'] as $r) {
            $want = 0;
            for ($y = DungeonGen::SUB * $r['gy'] + 1; $y <= DungeonGen::SUB * ($r['gy'] + $r['h']); $y++) {
                for ($x = DungeonGen::SUB * $r['gx'] + 1; $x <= DungeonGen::SUB * ($r['gx'] + $r['w']); $x++) {
                    if ($t['owner'][$y * $t['w'] + $x] !== ['room', (int) $r['id']]) {
                        $notRect++;
                    }
                    $want++;
                }
            }
            $got = 0;
            foreach ($t['owner'] as $o) {
                if ($o === ['room', (int) $r['id']]) {
                    $got++;
                }
            }
            if ($got !== $want) {
                $notRect++;
            }
        }

        $doorFaces = [];
        foreach ($t['doors'] as $dr) {
            $doorFaces[$dr['tile'] * 4 + $dr['dir']] = $dr;
        }
        $steps = [-$t['w'], 1, $t['w'], -1];
        $hasPartition = false;

        foreach ($t['owner'] as $tile => $o) {
            if ($o === null) {
                continue;
            }
            foreach ($steps as $dir => $off) {
                $n = $t['owner'][$tile + $off] ?? null;
                if ($n === null || $n === $o) {
                    continue;
                }
                // Two different places touching: either a doorway, or a
                // partition. Never a bare hole.
                $isDoor = isset($doorFaces[$tile * 4 + $dir]);
                $isWall = false;
                foreach ($t['walls'] as $w) {
                    if ($w['tile'] === $tile && $w['dir'] === $dir) {
                        $isWall = true;
                        break;
                    }
                }
                if (!$isDoor && !$isWall) {
                    $badDoor++;
                }
                if ($isWall) {
                    $hasPartition = true;
                    $partitions++;
                    // A partition is only ever between two passages. A chamber
                    // is never squeezed past.
                    if ($o[0] !== 'corridor' || $n[0] !== 'corridor') {
                        $bad++;
                    }
                }
                // A doorway is written from both sides, like the exit rows.
                if ($isDoor && !isset($doorFaces[($tile + $off) * 4 + (($dir + 2) % 4)])) {
                    $oneSided++;
                }
                // A passage never touches a chamber it does not join.
                if ($o[0] === 'corridor' && $n[0] === 'room') {
                    $e = $lv['corridors'][$o[1]];
                    if ((int) $n[1] !== (int) $e['a'] && (int) $n[1] !== (int) $e['b']) {
                        $abut++;
                    }
                }
            }
            // One tile wide: a passage never fills a two-by-two block of its
            // OWN tiles. Four tiles belonging to two passages that have
            // squeezed past each other is a different thing and is allowed —
            // there is a partition down the middle of it.
            if ($o[0] === 'corridor'
                && ($t['owner'][$tile + 1] ?? null) === $o
                && ($t['owner'][$tile + $t['w']] ?? null) === $o
                && ($t['owner'][$tile + $t['w'] + 1] ?? null) === $o) {
                $wide++;
            }
        }
        if ($hasPartition) {
            $partitionLevels++;
        }

        // THE ONE THAT MATTERS. Walk the tiles from the entrance's own tile and
        // see which places you can get to; it has to be every place the routed
        // graph says, and nothing else.
        $reach = flood($t, $t['spines']['room'][$lv['entrance']]);

        $adj = [];
        foreach ($lv['corridors'] as $e) {
            $ways++;
            if (!isset($routes[$e['id']])) {
                $dropped++;
                continue;
            }
            $adj[$e['a']][] = [$e['b'], (int) $e['id']];
            $adj[$e['b']][] = [$e['a'], (int) $e['id']];
        }
        $want = ['room:' . $lv['entrance'] => true];
        $queue = [$lv['entrance']];
        while ($queue) {
            $at = array_shift($queue);
            foreach ($adj[$at] ?? [] as [$to, $cid]) {
                $want['corridor:' . $cid] = true;
                if (!isset($want['room:' . $to])) {
                    $want['room:' . $to] = true;
                    $queue[] = $to;
                }
            }
        }
        ksort($want);
        ksort($reach);
        if ($want !== $reach) {
            $mismatch++;
        }
        // And every chamber is still reachable once the dropped ways are gone.
        foreach ($lv['rooms'] as $r) {
            if (!isset($want['room:' . $r['id']])) {
                $cutOff++;
            }
        }
    }
}

ok('the raster reaches exactly what the graph reaches', $mismatch === 0,
    "{$mismatch} of {$levels} levels disagreed");
ok('and no chamber is left without a way in', $cutOff === 0, "{$cutOff} were");
ok('no two passages stand on the same tile', $shared === 0, "{$shared} did");
ok('a passage never touches a chamber it does not join', $abut === 0, "{$abut} tiles did");
ok('every room is its own footprint, exactly', $notRect === 0, "{$notRect} tiles were not");
ok('nothing is carved into the border', $border === 0, "{$border} tiles were");
ok('two places never touch without a doorway or a wall', $badDoor === 0, "{$badDoor} faces did");
ok('and a doorway is written from both sides', $oneSided === 0, "{$oneSided} were not");
ok('every passage is one tile wide', $wide === 0, "{$wide} filled a square");
// Only the optional loops are ever lost — a spanning-tree edge that could not
// be routed would have shown up as a chamber cut off, above. Banded rather than
// forbidden, for the reason the squeeze below is: the router can be beaten by a
// plan that leaves one lane between two chambers and asks two passages for it.
ok('almost every way the graph rolls gets floor', $dropped * 200 < $ways,
    sprintf('%d of %d ways did not (%.2f%%)', $dropped, $ways, 100 * $dropped / max(1, $ways)));
// Two passages squeezing past each other with a thin wall between them is
// legitimate and rare; it is banded rather than forbidden so the number cannot
// drift without somebody deciding it should.
ok('and passages rarely have to squeeze past each other',
    $partitionLevels * 100 < $levels * 12,
    sprintf('%d of %d levels (%.1f%%), %d faces', $partitionLevels, $levels,
        100 * $partitionLevels / $levels, $partitions));

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
