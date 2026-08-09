<?php
/**
 * Exercises LocationEngine::routeTo across every pair of locations.
 *
 * Needs a database, unlike the other harnesses, because the whole thing under
 * test is a breadth-first search over `location_exits`. Run it in a browser at
 * /tools/test_route.php or from the CLI with `php tools/test_route.php`.
 *
 * Two different claims are made here and they are worth keeping apart:
 *
 *   The invariants   — hold for every pair, whatever the world happens to be.
 *                      A route is one hop or more, its chain ends at the
 *                      destination, it names a first step, and the id of that
 *                      first step is a real exit out of where you are standing.
 *                      These survive the world being re-authored.
 *   The world checks — assert things about the content as written: that the
 *                      Flagon and the Warlord's Hall are joined, and that the
 *                      long way there is genuinely long. These are meant to
 *                      fail if somebody deletes the road, which is the point.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$db = db();
$locations = $db->query(
    'SELECT l.id, l.location_key, l.name, r.name AS region
       FROM locations l
       LEFT JOIN regions r ON r.id = l.region_id
      ORDER BY l.id'
)->fetchAll();
$engine = new LocationEngine($db);

$pass = 0;
$fail = 0;
$check = function (bool $ok, string $what) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    echo "  FAIL  {$what}\n";
};

$byKey = [];
$nameById = [];
foreach ($locations as $l) {
    $byKey[$l['location_key']] = (int) $l['id'];
    $nameById[(int) $l['id']] = (string) $l['name'];
}

if (!$locations) {
    echo "No locations. Load sql/content.sql before running this.\n";
    exit(1);
}

printf("%d locations across %d region(s)\n\n", count($locations), count(array_unique(
    array_map(static fn ($l) => (string) $l['region'], $locations)
)));

// The exits out of each location, so a route's first step can be checked
// against a door that really exists rather than against routeTo's own answer.
$exitsFrom = [];
foreach ($db->query('SELECT from_location_id, to_location_id, label FROM location_exits')->fetchAll() as $e) {
    $exitsFrom[(int) $e['from_location_id']][(int) $e['to_location_id']] = (string) $e['label'];
}

$unreachable = 0;
$longest = ['hops' => 0, 'from' => '', 'to' => ''];

foreach ($locations as $from) {
    $fromId = (int) $from['id'];
    foreach ($locations as $to) {
        $toId = (int) $to['id'];
        $r = $engine->routeTo($fromId, $toId);

        if ($fromId === $toId) {
            $check($r['arrived'] === true, "standing still should arrive immediately");
            $check($r['hops'] === 0, "standing still should be zero hops");
            $check($r['reachable'] === true, "somewhere is always reachable from itself");
            $check($r['next_location_id'] === null, "standing still should name no next step");
            $check($r['route'] === [], "standing still should name no chain");
            continue;
        }

        if (!$r['reachable']) {
            $unreachable++;
            $check($r['hops'] === 0, "an unreachable place should report no hops");
            $check($r['via'] === null, "an unreachable place should name no first exit");
            $check($r['next_location_id'] === null, "an unreachable place should name no next step");
            printf("  %-22s -> %-22s  UNREACHABLE\n", $from['location_key'], $to['location_key']);
            continue;
        }

        $chain = "{$from['location_key']} -> {$to['location_key']}";
        $check($r['arrived'] === false, "somewhere else should not report arrived: {$chain}");
        $check($r['hops'] >= 1, "somewhere else should be at least one hop: {$chain}");
        $check(count($r['route']) === $r['hops'], "the chain length should match the hop count: {$chain}");
        $check(
            end($r['route']) === $to['name'],
            "the chain should end at the destination, got: " . implode(' -> ', $r['route'])
        );

        // The first step is what the client actually acts on — travel walks the
        // path one exit at a time — so it has to be a door out of the room the
        // party is standing in, not merely somewhere on the way.
        $next = $r['next_location_id'];
        $check($next !== null, "a route should name the next location: {$chain}");
        $check(
            $next !== null && isset($exitsFrom[$fromId][$next]),
            "the next step should be a real exit out of {$from['location_key']}, got id {$next}"
        );
        // And `via` is the words written on that door — or, for an exit left
        // unlabelled, the fallback naming where it leads. Anything else means
        // the label came off a different exit than the one being taken.
        $label = $next !== null ? ($exitsFrom[$fromId][$next] ?? null) : null;
        $expectedVia = ($label !== null && $label !== '')
            ? $label
            : 'the way to ' . ($nameById[$next] ?? '');
        $check(
            $r['via'] === $expectedVia,
            "via should be the first exit's own label: {$chain} gave '{$r['via']}', expected '{$expectedVia}'"
        );

        if ($r['hops'] > $longest['hops']) {
            $longest = ['hops' => $r['hops'], 'from' => $from['location_key'], 'to' => $to['location_key']];
        }
    }
}

printf(
    "  every pair walked; %d unreachable, longest journey %d hops (%s -> %s)\n\n",
    $unreachable,
    $longest['hops'],
    $longest['from'],
    $longest['to']
);

// --- the world as authored --------------------------------------------------
//
// The invariants above would still pass on an empty graph where nothing reaches
// anything. These say the road is actually there.

echo "The long walk\n";
echo str_repeat('-', 68) . "\n";

foreach (['flagon_common_room', 'warlord_hall'] as $key) {
    $check(isset($byKey[$key]), "the world should contain {$key}");
}

if (isset($byKey['flagon_common_room'], $byKey['warlord_hall'])) {
    $r = $engine->routeTo($byKey['flagon_common_room'], $byKey['warlord_hall']);
    $check($r['reachable'] === true, "a party in the Flagon can get to the Warlord's Hall");
    $check($r['arrived'] === false, "the Warlord's Hall is not the Flagon");
    // It crosses the town, the road and the warren, so a one-hop answer means
    // something has been wired together that should not be.
    $check($r['hops'] >= 3, "that journey should be several hops, got {$r['hops']}");
    $check(
        $r['next_location_id'] === ($byKey['high_street'] ?? null),
        "the first step out of the Flagon should be the High Street"
    );
    $check(
        is_string($r['via']) && $r['via'] !== '',
        "the route should name the door it leaves by"
    );
    $check(
        end($r['route']) === "The Warlord's Hall",
        "the chain should end in the Warlord's Hall"
    );
    printf(
        "  flagon_common_room -> warlord_hall  %d hop(s) via '%s'\n    %s\n",
        $r['hops'],
        $r['via'],
        implode(' -> ', $r['route'])
    );

    // Backwards too. The graph is authored as pairs of one-way exits, so a
    // return trip is a separate claim and has been missing before.
    $back = $engine->routeTo($byKey['warlord_hall'], $byKey['flagon_common_room']);
    $check($back['reachable'] === true, "and can get back again");
    $check($back['hops'] >= 3, "the way back should be several hops too");
    printf("  warlord_hall -> flagon_common_room  %d hop(s) via '%s'\n", $back['hops'], $back['via']);
}

// A short hop inside one building, where the answer is checkable by eye: the
// cellar is one door below the common room and the label says so.
if (isset($byKey['flagon_common_room'], $byKey['flagon_cellar'])) {
    $r = $engine->routeTo($byKey['flagon_common_room'], $byKey['flagon_cellar']);
    $check($r['hops'] === 1, "the cellar is one step from the common room, got {$r['hops']}");
    $check(
        $r['next_location_id'] === $byKey['flagon_cellar'],
        "a one-hop route's next step is the destination"
    );
    $check(count($r['route']) === 1, "a one-hop route names one place");
    printf("  flagon_common_room -> flagon_cellar  1 hop via '%s'\n", $r['via']);
}

// Nothing routes to a location that does not exist, and asking must not throw.
$r = $engine->routeTo((int) $locations[0]['id'], 999999);
$check($r['reachable'] === false, "an id that is not a location should be unreachable");
$check($r['next_location_id'] === null, "and should name no next step");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
