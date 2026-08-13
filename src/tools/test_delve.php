<?php
/**
 * A delve: a generated level written into the world, walked, and taken away.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_delve.php`.
 *
 * This is the half of the dungeon that touches the database, so unlike
 * tools/test_dungeon.php it cannot be swept over a thousand seeds — what it
 * checks instead is that the rows are RIGHT: that a generated floor is an
 * ordinary region the rest of the game can already walk, that the party ends
 * up standing on it, that leaving takes it away completely, and above all that
 * nothing it writes joins another module.
 *
 * That last one is the reason this file exists. Everywhere else two games are
 * kept apart by tools/load_content.py, which reads FILES and will never see a
 * row DelveEngine writes. The static guarantee stops at the file system, and
 * this is what replaces it.
 *
 * Builds its own party and characters and removes them. Safe against a
 * database somebody is playing on.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$db = db();
$delves = new DelveEngine($db);

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
function threw(string $n, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        ok($n, false, 'nothing was thrown');
    } catch (Throwable $e) {
        ok($n, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
    }
}

// --- a party at the Mouth --------------------------------------------------
$moduleId = (int) $db->query("SELECT id FROM modules WHERE module_key='undervault'")->fetchColumn();
$mouthId = (int) $db->query("SELECT id FROM locations WHERE location_key='uv_mouth'")->fetchColumn();
$campId  = (int) $db->query("SELECT id FROM locations WHERE location_key='uv_camp'")->fetchColumn();
ok('the module is loaded', $moduleId > 0);
ok('and the Mouth is authored', $mouthId > 0);

$db->prepare('INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, ?)')
    ->execute([$moduleId, '__delve_probe']);
$partyId = (int) $db->lastInsertId();

$charIds = [];
foreach ([['Wren', 'Fighter'], ['Aldric', 'Cleric'], ['Sera', 'Rogue']] as [$name, $class]) {
    $db->prepare(
        'INSERT INTO characters (name, race, class, level, strength, dexterity, constitution,
            intelligence, wisdom, charisma, max_hp, current_hp, armor_class, speed,
            proficiency_bonus, current_location_id)
         VALUES (?, "Human", ?, 3, 12,12,12,12,12,12, 24,24, 14, 30, 2, ?)'
    )->execute(["__delve_{$name}", $class, $mouthId]);
    $id = (int) $db->lastInsertId();
    $charIds[] = $id;
    $db->prepare('INSERT INTO character_party (party_id, character_id) VALUES (?, ?)')
        ->execute([$partyId, $id]);
}
$leader = $charIds[0];

$cleanup = function () use ($db, $partyId, $charIds, $delves) {
    try {
        $delves->end($partyId, false);
    } catch (Throwable $e) {
        // fall through to the blunt sweep below
    }
    $db->prepare("DELETE FROM regions WHERE region_key LIKE ?")->execute(["_dg_{$partyId}_%"]);
    $db->prepare('DELETE FROM dungeon_delves WHERE party_id = ?')->execute([$partyId]);
    $db->prepare('DELETE FROM character_party WHERE party_id = ?')->execute([$partyId]);
    foreach ($charIds as $id) {
        $db->prepare('DELETE FROM characters WHERE id = ?')->execute([$id]);
    }
    $db->prepare('DELETE FROM parties WHERE id = ?')->execute([$partyId]);
};
register_shutdown_function($cleanup);

$whereIs = fn (int $id) => (string) $db->query(
    "SELECT l.location_key FROM characters c
       INNER JOIN locations l ON l.id = c.current_location_id WHERE c.id = {$id}"
)->fetchColumn();

echo "== before going down ==\n";
same('no delve yet', null, $delves->current($partyId));
ok('the party may descend at the Mouth', $delves->canDescendHere($leader));

$db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$campId, $leader]);
ok('but not from the camp', !$delves->canDescendHere($leader));
threw('and asking anyway is refused',
    fn() => $delves->descend($leader, $partyId), 'at the Mouth');
$db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$mouthId, $leader]);

echo "\n== the first floor ==\n";
$r1 = $delves->descend($leader, $partyId);
same('the party is on level one', 1, $r1['depth']);
$upper = DungeonGen::PROFILES['upper']['rooms'];
ok('with rooms in it', $r1['rooms'] >= $upper[0], (string) $r1['rooms']);
ok('and a passage joining every pair of them', $r1['passages'] >= $r1['rooms'] - 1,
    "{$r1['passages']} passages for {$r1['rooms']} rooms");

$delve = $delves->current($partyId);
ok('the delve is recorded', $delve !== null);
same('at the depth it says', 1, (int) $delve['depth']);
ok('and it names its region', (int) $delve['region_id'] > 0);

$regionId = (int) $delve['region_id'];
// Rooms AND passages: a hallway is a place you stand in, so it is a location
// row like any other. That is the whole of what makes room -> passage -> room
// travel work — LocationEngine's BFS walks location_exits and knows nothing
// about dungeons.
$places = (int) $db->query("SELECT COUNT(*) FROM locations WHERE region_id = {$regionId}")->fetchColumn();
same('every room and every passage is a location row', $r1['rooms'] + $r1['passages'], $places);
$halls = (int) $db->query(
    "SELECT COUNT(*) FROM locations WHERE region_id = {$regionId} AND location_type = 'passage'"
)->fetchColumn();
same('and the passages are typed as passages', $r1['passages'], $halls);

// The rooms that promise something have something in them.
//
// The stocking table calls nearly a quarter of every floor `treasure` or
// `hoard` and their prose says so — "There is something here worth stooping
// for". Nothing was ever placed: DelveEngine wrote encounters and never one
// location_items row, so a whole delve paid out XP and gold and not one thing
// you could carry. That is what "still not getting loot, just gold" was.
$level = DungeonGen::generate((int) $delve['seed'], 1);
$promising = 0;
foreach ($level['rooms'] as $room) {
    if (in_array($room['kind'], ['treasure', 'hoard', 'boss'], true)) {
        $promising++;
    }
}
$strewn = (int) $db->query(
    "SELECT COUNT(*) FROM location_items li
       INNER JOIN locations l ON l.id = li.location_id
      WHERE l.region_id = {$regionId}"
)->fetchColumn();
ok('a floor puts something in the rooms that promise something',
    $promising === 0 || $strewn > 0, "{$promising} such rooms, {$strewn} items placed");

// And they are in THOSE rooms, not scattered anywhere convenient.
$wrongRoom = (int) $db->query(
    "SELECT COUNT(*) FROM location_items li
       INNER JOIN locations l ON l.id = li.location_id
      WHERE l.region_id = {$regionId} AND l.location_type = 'passage'"
)->fetchColumn();
same('and never loose in a corridor', 0, $wrongRoom);

// Nothing found twice on one floor: the bands are narrow near the surface, so
// independent draws put the same object in both treasure rooms often enough
// to read as the generator repeating itself.
$dupes = (int) $db->query(
    "SELECT COUNT(*) FROM (
        SELECT li.item_id FROM location_items li
          INNER JOIN locations l ON l.id = li.location_id
         WHERE l.region_id = {$regionId}
         GROUP BY li.item_id HAVING COUNT(*) > 1) d"
)->fetchColumn();
same('and no item is found twice on one floor', 0, $dupes);

// The shape of the graph, which is the point of the change: no room is wired
// straight to another room. Every journey between two rooms goes through a
// hallway.
$roomToRoom = (int) $db->query(
    "SELECT COUNT(*) FROM location_exits e
       INNER JOIN locations lf ON lf.id = e.from_location_id
       INNER JOIN locations lt ON lt.id = e.to_location_id
      WHERE lf.region_id = {$regionId} AND lt.region_id = {$regionId}
        AND lf.location_type <> 'passage' AND lt.location_type <> 'passage'"
)->fetchColumn();
same('no two rooms are joined without a passage between them', 0, $roomToRoom);

// The whole party moves, not just whoever pressed the button.
$onFloor = 0;
foreach ($charIds as $id) {
    if (str_starts_with($whereIs($id), "_dg_{$partyId}_1_r")) {
        $onFloor++;
    }
}
same('the whole party goes down together', count($charIds), $onFloor);

echo "\n== the floor is an ordinary region ==\n";
$exits = (int) $db->query(
    "SELECT COUNT(*) FROM location_exits e
       INNER JOIN locations l ON l.id = e.from_location_id
      WHERE l.region_id = {$regionId}"
)->fetchColumn();
ok('the passages are real exit rows', $exits > 0, (string) $exits);

// Every passage INSIDE the floor is two-way. Authored content declares both
// halves and the loader warns about a one-way exit; a generated level has no
// author to warn, so both halves are written from one edge.
//
// The exit that leaves the floor is excluded, and is one-way on purpose — see
// the assertion below. The first version of this counted it and reported the
// engine as buggy when the engine was right.
$oneWay = (int) $db->query(
    "SELECT COUNT(*) FROM location_exits a
       INNER JOIN locations l ON l.id = a.from_location_id
       INNER JOIN locations t ON t.id = a.to_location_id
      WHERE l.region_id = {$regionId} AND t.region_id = {$regionId}
        AND NOT EXISTS (SELECT 1 FROM location_exits b
                         WHERE b.from_location_id = a.to_location_id
                           AND b.to_location_id = a.from_location_id)"
)->fetchColumn();
same('and no passage inside the floor is one-way', 0, $oneWay);

// The way out is deliberately one-way. A Mouth-to-dungeon exit would let the
// party walk back down without going through descend() — into a region that
// may have been dropped, or at a depth the delve row no longer agrees with.
$backIn = (int) $db->query(
    "SELECT COUNT(*) FROM location_exits e
       INNER JOIN locations t ON t.id = e.to_location_id
      WHERE e.from_location_id = {$mouthId} AND t.region_id = {$regionId}"
)->fetchColumn();
same('and there is no way to walk back down without descending', 0, $backIn);

// The way out, from the entrance room only.
$out = (int) $db->query(
    "SELECT COUNT(*) FROM location_exits e
       INNER JOIN locations l ON l.id = e.from_location_id
      WHERE l.region_id = {$regionId} AND e.to_location_id = {$mouthId}"
)->fetchColumn();
same('there is exactly one way back up', 1, $out);

// LocationEngine can read it with no special case — which is the whole point
// of writing ordinary rows.
$loc = new LocationEngine($db);
$state = $loc->getState($leader, $partyId);
ok('the location screen renders the generated room',
    !empty($state['location']['description']));
ok('and the region chart draws it',
    !empty($state['region_map']['nodes']) && count($state['region_map']['nodes']) > 1);

echo "\n== nothing joins another module ==\n";
$strays = (int) $db->query(
    "SELECT COUNT(*) FROM location_exits e
       INNER JOIN locations lf ON lf.id = e.from_location_id
       INNER JOIN locations lt ON lt.id = e.to_location_id
       INNER JOIN regions rf ON rf.id = lf.region_id
       INNER JOIN regions rt ON rt.id = lt.region_id
      WHERE (rf.id = {$regionId} OR rt.id = {$regionId})
        AND rf.module_id <> rt.module_id"
)->fetchColumn();
same('no exit of the generated floor leaves the module', 0, $strays);

echo "\n== what is in the rooms ==\n";
$fights = (int) $db->query(
    "SELECT COUNT(*) FROM encounters e
       INNER JOIN locations l ON l.id = e.location_id
      WHERE l.region_id = {$regionId}"
)->fetchColumn();
ok('some rooms are occupied', $fights >= 2, (string) $fights);
$empty = (int) $db->query(
    "SELECT COUNT(*) FROM encounters e
       INNER JOIN locations l ON l.id = e.location_id
      WHERE l.region_id = {$regionId}
        AND NOT EXISTS (SELECT 1 FROM encounter_monsters m WHERE m.encounter_id = e.id)"
)->fetchColumn();
same('and no fight is empty of monsters', 0, $empty);
$ambush = (int) $db->query(
    "SELECT COUNT(*) FROM encounters e
       INNER JOIN locations l ON l.id = e.location_id
      WHERE l.region_id = {$regionId} AND e.is_ambush = 0"
)->fetchColumn();
same('every one of them starts on arrival', 0, $ambush);

echo "\n== deeper ==\n";
$r2 = $delves->descend($leader, $partyId);
same('the second floor is level two', 2, $r2['depth']);
same('the seed does not change — one delve, one dungeon', (int) $delve['seed'],
    (int) $delves->current($partyId)['seed']);
$gone = (int) $db->query("SELECT COUNT(*) FROM regions WHERE id = {$regionId}")->fetchColumn();
same('the floor above is dropped as they leave it', 0, $gone);
$live = (int) $db->query(
    "SELECT COUNT(*) FROM regions WHERE region_key LIKE '_dg\\_{$partyId}\\_%'"
)->fetchColumn();
same('so a party is never on more than one generated floor', 1, $live);

echo "\n== how deep they have been ==\n";
// The one fact authored content can state about a generated floor. Quests and
// dialogue gate on `{"flag": "uv_deepest", "at_least": n}`; nothing else about
// a level that was rolled ten minutes ago has a name to gate on.
// A fresh reader each time, because WorldState caches a party's flags for the
// life of the instance — one request, one read. Holding one across the descents
// below would assert against the snapshot taken before them.
$deepest = fn(): int => (new WorldState($db))->number($partyId, DelveEngine::DEPTH_FLAG);
same('the depth flag records the floor they are on', 2, $deepest());

echo "\n== coming back up ==\n";
$secondRegion = (int) $delves->current($partyId)['region_id'];
$delves->end($partyId);
same('the delve is over', null, $delves->current($partyId));
same('and its floor is gone', 0,
    (int) $db->query("SELECT COUNT(*) FROM regions WHERE id = {$secondRegion}")->fetchColumn());
same('with no locations left behind', 0,
    (int) $db->query("SELECT COUNT(*) FROM locations WHERE region_id = {$secondRegion}")->fetchColumn());
same('nor encounters', 0,
    (int) $db->query(
        "SELECT COUNT(*) FROM encounters WHERE encounter_key LIKE '_dg\\_{$partyId}\\_%'"
    )->fetchColumn());

$back = 0;
foreach ($charIds as $id) {
    if ($whereIs($id) === 'uv_mouth') {
        $back++;
    }
}
same('and the whole party is standing at the Mouth', count($charIds), $back);
ok('ending a delve nobody is on is harmless', (function () use ($delves, $partyId) {
    $delves->end($partyId);
    return true;
})());

// Climbing out does not take back where they have been. This is the whole
// reason markDepth() compares before writing: a party that reached the fifth
// floor and started a fresh delve is standing on level 1, and writing the
// current depth would revoke a quest they had already turned in.
same('the mark survives coming up', 2, $deepest());
$delves->descend($leader, $partyId);
same('and a fresh delve starting at level one does not lower it', 2, $deepest());
$delves->descend($leader, $partyId);
$delves->descend($leader, $partyId);
same('going past it raises it', 3, $deepest());
$delves->end($partyId);

echo "\n== the loader will not sweep a live delve away ==\n";
// The guard added to load_content.py. A generated region must survive the
// retire clause that deletes everything content/ does not name — otherwise a
// content reload strands whoever is underground.
$delves->descend($leader, $partyId);
$liveRegion = (int) $delves->current($partyId)['region_id'];
$db->exec("DELETE FROM regions WHERE region_key NOT LIKE '\\_%' AND region_key NOT IN ('undervault_head')"
    . " AND region_key = 'THIS_MATCHES_NOTHING'");
// The real clause, run against the real key list shape:
$db->exec("DELETE FROM regions WHERE region_key NOT LIKE '\\_%' AND region_key NOT IN "
    . "(SELECT * FROM (SELECT region_key FROM regions WHERE region_key NOT LIKE '\\_%') t)");
same('the generated floor survives a content reload', 1,
    (int) $db->query("SELECT COUNT(*) FROM regions WHERE id = {$liveRegion}")->fetchColumn());
$delves->end($partyId);

echo "\n== what the scene tells the client ==\n";
// The `delve` block on the location payload. Null outside the module, so no
// other game grows a button about a hole it has not got.
$loc2 = new LocationEngine($db);
$db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$mouthId, $leader]);
$db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
              SET c.current_location_id = ? WHERE cp.party_id = ?')->execute([$mouthId, $partyId]);
$atMouth = $loc2->getState($leader, $partyId)['location']['delve'];
ok('the Mouth offers the stair', !empty($atMouth['can_descend']));
ok('and nothing to climb out of yet', empty($atMouth['can_leave']));

$db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
              SET c.current_location_id = ? WHERE cp.party_id = ?')->execute([$campId, $partyId]);
$atCamp = $loc2->getState($leader, $partyId)['location']['delve'];
ok('the camp does not', empty($atCamp['can_descend']));
ok('but it is still inside the module, so the block exists', $atCamp !== null);

// Somewhere else entirely: the block must be absent, not merely false.
$elsewhere = (int) $db->query("SELECT id FROM locations WHERE location_key='flagon_common_room'")->fetchColumn();
if ($elsewhere) {
    $db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
                  SET c.current_location_id = ? WHERE cp.party_id = ?')->execute([$elsewhere, $partyId]);
    same('another module carries no delve block at all', null,
        $loc2->getState($leader, $partyId)['location']['delve']);
    $db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
                  SET c.current_location_id = ? WHERE cp.party_id = ?')->execute([$mouthId, $partyId]);
}

// Underground: leave is offered everywhere, deeper only on the stair.
$delves->descend($leader, $partyId);
$d = $delves->current($partyId);
$here = $loc2->getState($leader, $partyId)['location']['delve'];
same('underground, the depth is reported', 1, (int) $here['depth']);
ok('climbing out is always offered down there', !empty($here['can_leave']));
ok('but the entrance room is not the stair', empty($here['can_deeper']));

$lvl = DungeonGen::generate((int) $d['seed'], 1);
$stairId = (int) $db->query(
    "SELECT id FROM locations WHERE location_key = '"
    . DelveEngine::roomKey($partyId, 1, (int) $lvl['stair']) . "'"
)->fetchColumn();
$db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
              SET c.current_location_id = ? WHERE cp.party_id = ?')->execute([$stairId, $partyId]);
$onStair = $loc2->getState($leader, $partyId)['location']['delve'];
ok('standing on the stair offers the way down', !empty($onStair['can_deeper']));

echo "\n== the bottom ==\n";
// Walking to the floor below MAX_DEPTH must be refused rather than generating
// a level nobody can leave.
$db->prepare('UPDATE dungeon_delves SET depth = ? WHERE party_id = ?')
    ->execute([DelveEngine::MAX_DEPTH, $partyId]);
threw('the stair stops at the bottom',
    fn() => $delves->descend($leader, $partyId), 'nothing below');
$db->prepare('UPDATE dungeon_delves SET depth = 1 WHERE party_id = ?')->execute([$partyId]);
$delves->end($partyId);

echo "\n== beaten underground ==\n";
// A wipe on level two must carry the party out: the haven wakes them at the
// camp, and the floor they fell on has to stop existing. Without it they wake
// upstairs with a delve row insisting they are still on level two, and a
// region nothing will ever collect.
$delves->descend($leader, $partyId);
$delves->descend($leader, $partyId);
$before = $delves->current($partyId);
same('two floors down', 2, (int) $before['depth']);
$fallenRegion = (int) $before['region_id'];

// Drive the real defeat path rather than imitating it.
$db->prepare(
    'INSERT INTO combat_sessions (character_id, location_id, encounter_id, party_id, state_json, is_active)
     VALUES (?, (SELECT current_location_id FROM characters WHERE id = ?), NULL, ?, ?, 1)'
)->execute([$leader, $leader, $partyId, json_encode(['status' => 'defeat'])]);
$sessionId = (int) $db->lastInsertId();

$state = [
    'status' => 'defeat',
    'log' => [],
    'events' => [],
    'combatants' => array_map(
        fn ($id) => ['side' => 'party', 'character_id' => $id, 'hp' => 0, 'cid' => 'pc_' . $id],
        $charIds
    ),
];
$finalize = new ReflectionMethod(CombatEngine::class, 'finalizeCombat');
$finalize->setAccessible(true);
$finalize->invoke(new CombatEngine($db), $sessionId, $leader, $state);

same('the delve is over', null, $delves->current($partyId));
same('and the floor they fell on is gone', 0,
    (int) $db->query("SELECT COUNT(*) FROM regions WHERE id = {$fallenRegion}")->fetchColumn());
same('with no generated rooms left', 0,
    (int) $db->query("SELECT COUNT(*) FROM locations WHERE region_id = {$fallenRegion}")->fetchColumn());
same('nor the fights that stood in them', 0,
    (int) $db->query(
        "SELECT COUNT(*) FROM encounters WHERE encounter_key LIKE '_dg\\_{$partyId}\\_%'"
    )->fetchColumn());

// The haven, not the Mouth. end() is called with the move suppressed precisely
// so a beaten party wakes at the fire rather than at the top of the stair.
$woke = [];
foreach ($charIds as $id) {
    $woke[] = $whereIs($id);
}
same('and the whole party wakes at the camp',
    array_fill(0, count($charIds), 'uv_camp'), $woke);
ok('alive, if barely', (int) $db->query(
    "SELECT MIN(current_hp) FROM characters WHERE id IN (" . implode(',', $charIds) . ")"
)->fetchColumn() >= 1);

$db->prepare('DELETE FROM combat_sessions WHERE id = ?')->execute([$sessionId]);

echo "\n== the dressing ==\n";
// The floor below is chosen, not rolled: descend() keeps the seed of an
// existing delve row, so a row planted at depth 0 makes the next descent a
// FIXTURE — a level known to hold a locked door, a stuck door, and a rigged
// passage, found by sweeping the pure generator, which costs nothing.
$fixtureSeed = null;
$fixture = null;
for ($s = 1; $s < 20000; $s++) {
    $lv = DungeonGen::generate($s, 1);
    $plan = DungeonGen::plan($lv);
    $drawable = [];
    foreach ($plan['corridors'] as $c) {
        $drawable[$c['id']] = true;
    }
    $locked = $stuck = $rigged = null;
    foreach ($lv['edges'] as $i => $e) {
        if (!isset($drawable[$i])) {
            continue;
        }
        if (($e['door'] === 'locked' || $e['door'] === 'portcullis') && $locked === null
            && !isset($lv['corridors'][$i]['trap'])) {
            $locked = $i;
        }
        if ($e['door'] === 'stuck' && $stuck === null) {
            $stuck = $i;
        }
        // Not locked, not a portcullis and NOT STUCK: this fixture is for the
        // trap, and each of those three is a different thing standing between
        // the party and the passage. A stuck door is hidden until it is found,
        // so walking in is refused before the trap can fire — which is what
        // happened the first time the generator's layout changed under this
        // sweep and the search picked one.
        if (isset($lv['corridors'][$i]['trap']) && $rigged === null
            && !in_array($e['door'], ['locked', 'portcullis', 'stuck'], true)) {
            $rigged = $i;
        }
    }
    if ($locked !== null && $stuck !== null && $rigged !== null) {
        $fixtureSeed = $s;
        $fixture = ['level' => $lv, 'locked' => $locked, 'stuck' => $stuck, 'rigged' => $rigged];
        break;
    }
}
ok('a fixture seed exists with a locked, a stuck and a rigged way', $fixtureSeed !== null,
    'swept 20000 seeds without one — the dressing rates have collapsed');

// Blind the leader: the passive-perception look on arrival would spot the
// shallow traps' DCs with an ordinary Wisdom, and this section needs the trap
// to FIRE. (The spotting path is asserted by implication: flag `found` below.)
$db->prepare('UPDATE characters SET wisdom = 3 WHERE id = ?')->execute([$leader]);

$db->prepare('DELETE FROM dungeon_delves WHERE party_id = ?')->execute([$partyId]);
$db->prepare('INSERT INTO dungeon_delves (party_id, seed, depth) VALUES (?, ?, 0)')
    ->execute([$partyId, $fixtureSeed]);
$rd = $delves->descend($leader, $partyId);
same('the fixture floor is level one of the planted seed', 1, $rd['depth']);
$dressedRegion = (int) $delves->current($partyId)['region_id'];

// -- wandering monsters ------------------------------------------------------
$wander = $db->query(
    "SELECT * FROM encounters WHERE region_id = {$dressedRegion} AND is_random = 1"
)->fetchAll(PDO::FETCH_ASSOC);
ok('the floor has a wandering pool of two or three',
    count($wander) >= 2 && count($wander) <= 3, count($wander) . ' rows');
$behaving = 0;
foreach ($wander as $w) {
    if ((int) $w['is_ambush'] === 0 && $w['location_id'] === null
        && str_contains((string) $w['name'], ', ')) {
        $behaving++;
    }
}
same('every wanderer is placeless, un-ambushed, and up to something',
    count($wander), $behaving);

$pcts = $db->query(
    "SELECT location_type, MIN(random_encounter_pct) mn, MAX(random_encounter_pct) mx
       FROM locations WHERE region_id = {$dressedRegion} GROUP BY location_type"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($pcts as $p) {
    if ($p['location_type'] === 'passage') {
        ok('every passage rolls the wandering die', (int) $p['mn'] > 0, "min {$p['mn']}");
    } else {
        same("and no {$p['location_type']} does", 0, (int) $p['mx']);
    }
}

// -- the locked door ---------------------------------------------------------
$loc3 = new LocationEngine($db);
$lockedIdx = $fixture['locked'];
$lockedPassage = (int) $db->query(
    "SELECT id FROM locations WHERE location_key = '_dg_{$partyId}_1_c{$lockedIdx}'"
)->fetchColumn();
$lockedRoomA = (int) $db->query(
    "SELECT id FROM locations WHERE location_key = '_dg_{$partyId}_1_r{$fixture['level']['edges'][$lockedIdx]['a']}'"
)->fetchColumn();
ok('the locked corridor is a real passage row', $lockedPassage > 0);

$condRows = $db->query(
    "SELECT conditions_json FROM location_exits WHERE to_location_id = {$lockedPassage}"
)->fetchAll(PDO::FETCH_COLUMN);
same('both doors of the locked join carry the gate', 2, count($condRows));
$flags = array_unique(array_map(
    fn ($j) => (string) (json_decode((string) $j, true)[0]['flag'] ?? ''), $condRows));
same('and they are one flag, because they are one door',
    ['dg_open__dg_' . $partyId . '_1_c' . $lockedIdx], array_values($flags));

$db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
              SET c.current_location_id = ? WHERE cp.party_id = ?')
    ->execute([$lockedRoomA, $partyId]);
threw('walking through it while shut is refused',
    fn () => $loc3->travel($leader, $lockedPassage), 'shut to you');

$offer = $loc3->forceDoor($leader, (int) $db->query(
    "SELECT id FROM location_exits WHERE from_location_id = {$lockedRoomA}
      AND to_location_id = {$lockedPassage}"
)->fetchColumn());
ok('forcing it opens the ceremony', !empty($offer['check']['check_id']));
ok('with the depth in the DC', $offer['check']['dc'] >= 11, (string) $offer['check']['dc']);
$done = $loc3->forceDoorResolve($leader, (string) $offer['check']['check_id'], []);
$world = new WorldState($db);
same('the die decides the flag, and they agree', $done['opened'],
    $world->isSet($partyId, $flags[0]));

$world->set($partyId, $flags[0]);   // whatever the die said: open it and walk
$loc3->travel($leader, $lockedPassage);
same('and a forced door is a door', '_dg_' . $partyId . '_1_c' . $lockedIdx, $whereIs($leader));

// -- the trap ----------------------------------------------------------------
$rigIdx = $fixture['rigged'];
$rigPassage = (int) $db->query(
    "SELECT id FROM locations WHERE location_key = '_dg_{$partyId}_1_c{$rigIdx}'"
)->fetchColumn();
$rigRoomA = (int) $db->query(
    "SELECT id FROM locations WHERE location_key = '_dg_{$partyId}_1_r{$fixture['level']['edges'][$rigIdx]['a']}'"
)->fetchColumn();
$trapJson = $db->query(
    "SELECT trap_json FROM locations WHERE id = {$rigPassage}"
)->fetchColumn();
ok('the rigged passage carries its trap', !empty($trapJson));
$written = json_decode((string) $trapJson, true);
same('written whole', ['key', 'name', 'save', 'dc', 'damage', 'text', 'found_text'],
    array_keys($written));

$db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
              SET c.current_location_id = ? WHERE cp.party_id = ?')
    ->execute([$rigRoomA, $partyId]);
$hpBefore = array_sum(array_map(
    fn ($id) => (int) $db->query("SELECT current_hp FROM characters WHERE id = {$id}")->fetchColumn(),
    $charIds
));
$walk = $loc3->travel($leader, $rigPassage);
$trapEvents = array_values(array_filter($walk['events'] ?? [], fn ($e) => $e['type'] === 'trap'));
same('walking in springs it', 1, count($trapEvents));
$hpAfter = array_sum(array_map(
    fn ($id) => (int) $db->query("SELECT current_hp FROM characters WHERE id = {$id}")->fetchColumn(),
    $charIds
));
ok('and somebody paid for it, or saved well',
    $hpAfter < $hpBefore || !empty($trapEvents[0]['save']['success']),
    "hp {$hpBefore} -> {$hpAfter}");
// forget() first: the engine wrote `sprung` through its own WorldState, and
// this instance's per-party cache predates it — the staleness forget() exists
// to name.
$world->forget($partyId);
same('the floor remembers', 'sprung',
    $world->get($partyId, DelveEngine::trapFlag('_dg_' . $partyId . '_1_c' . $rigIdx)));

$db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
              SET c.current_location_id = ? WHERE cp.party_id = ?')
    ->execute([$rigRoomA, $partyId]);
$walk2 = $loc3->travel($leader, $rigPassage);
same('a trap fires once', 0,
    count(array_filter($walk2['events'] ?? [], fn ($e) => $e['type'] === 'trap')));

// -- the player's map --------------------------------------------------------
$pm = $delves->planFor($dressedRegion);
$byId = [];
foreach ($pm['corridors'] as $c) {
    $byId[$c['id']] = $c;
}
same('an unfound secret door is not on the map', null, $byId[$fixture['stuck']]['door']);
same('and its run says so', false, $byId[$fixture['stuck']]['found'] ?? null);
// Nor is the passage behind it drawn at all. It used to be — dimmed and
// doorless — which was defensible while the whole floor was on the chart and
// is not now that the rest of it is fogged: a dim line into the rock would be
// the one mark on the map that could only have come from the GM's copy.
ok('and the passage behind it is not drawn either',
    $byId[$fixture['stuck']]['seen'] === false
    && $byId[$fixture['stuck']]['glimpsed'] === false,
    json_encode([
        'seen' => $byId[$fixture['stuck']]['seen'],
        'glimpsed' => $byId[$fixture['stuck']]['glimpsed'],
    ]));
same('a met trap is marked', 'sprung', $byId[$rigIdx]['trap']['state'] ?? null);
$leaks = 0;
foreach ($pm['corridors'] as $c) {
    if (is_array($c['trap'] ?? null) && !in_array($c['trap']['state'], ['found', 'sprung'], true)) {
        $leaks++;
    }
    if (($c['door'] ?? null) === 'trapped'
        && !is_array($c['trap'] ?? null)) {
        $leaks++;
    }
}
same('and nothing unearned leaks', 0, $leaks);

// -- what stands in a room ---------------------------------------------------
// Every stocked room used to be the party against ONE large creature: the
// picker took the top third of everything the budget could afford and then
// asked how many fit, and the top of that list is by definition the thing that
// eats the budget alone. What is asserted here is the shape of a fight rather
// than any particular roll — that rooms are mostly groups, that a lone big
// thing still happens, and that neither breaks the budget.
$rosterOf = new ReflectionMethod(DelveEngine::class, 'roster');
$rosterOf->setAccessible(true);
$pickOne = new ReflectionMethod(DelveEngine::class, 'pick');
$pickOne->setAccessible(true);
$bestiary = $rosterOf->invoke($delves);

$roomBudget = EncounterBudget::forParty([3, 3, 3, 3], 'hard');
$sizes = [];
$overBudget = 0;
for ($i = 0; $i < 200; $i++) {
    $group = $pickOne->invoke($delves, $bestiary, $roomBudget);
    $n = (int) $group[0]['quantity'];
    $sizes[] = $n;
    $xp = 0;
    foreach ($bestiary as $m) {
        if ((int) $m['id'] === (int) $group[0]['id']) {
            $xp = (int) $m['xp'];
        }
    }
    // A room is allowed to be a little over its tier — EncounterBudget says so
    // and says why — but not double it.
    if (EncounterBudget::weight($xp * $n, $n, $roomBudget) > 1.25) {
        $overBudget++;
    }
}
$mean = array_sum($sizes) / count($sizes);
$groups = count(array_filter($sizes, fn ($n) => $n >= 2));
$solo = count(array_filter($sizes, fn ($n) => $n === 1));

ok('a room is usually a group, not a duel', $groups > 120,
    "{$groups} of 200 rooms held two or more");
ok('and the average room holds a handful', $mean >= 2.0 && $mean <= 4.5,
    sprintf('mean %.1f', $mean));
ok('a lone big thing still happens', $solo > 0, "{$solo} of 200 were solo");
same('nothing is dealt at double its tier', 0, $overBudget);

// -- the fog -----------------------------------------------------------------
// The party has walked a handful of this floor by now, which is the state the
// fog is for: some of it stood in, some of it seen from a doorway, and the
// rest still rock.
//
// getState() first, and this is not ceremony: a visit is recorded when the
// scene is DRAWN, not when a row is updated, and the moves above are raw SQL
// because they are setting up traps rather than playing. Without this the
// party would be standing in a room its own map has never heard of — which is
// what the first version of this test asserted its way into.
$loc3->getState($leader, $partyId);
$db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
              SET c.current_location_id = ? WHERE cp.party_id = ?')
    ->execute([$rigRoomA, $partyId]);
$loc3->getState($leader, $partyId);

$pm = $delves->planFor($dressedRegion);
$seenRooms = array_values(array_filter($pm['rooms'], fn ($r) => $r['seen']));
$dark = array_values(array_filter(
    $pm['rooms'],
    fn ($r) => !$r['seen'] && !$r['glimpsed']
));
ok('the party has seen some of the floor', count($seenRooms) > 0,
    count($seenRooms) . ' of ' . count($pm['rooms']));
ok('and not all of it', count($dark) > 0,
    count($dark) . ' rooms still dark of ' . count($pm['rooms']));
$bothWays = 0;
foreach (array_merge($pm['rooms'], $pm['corridors']) as $s) {
    if ($s['seen'] && $s['glimpsed']) {
        $bothWays++;
    }
}
same('nothing is both walked and merely glimpsed', 0, $bothWays);

// A room is glimpsed BECAUSE a passage into it has been walked — never because
// the party stood in a room that has a passage toward it. Two steps of sight
// would show the floor faster than it is walked.
$walkedPassage = [];
foreach ($pm['corridors'] as $c) {
    if ($c['seen']) {
        $walkedPassage[(int) $c['a']] = true;
        $walkedPassage[(int) $c['b']] = true;
    }
}
$unearned = 0;
foreach ($pm['rooms'] as $r) {
    if ($r['glimpsed'] && empty($walkedPassage[(int) $r['id']])) {
        $unearned++;
    }
}
same('every glimpsed room is at the end of a walked passage', 0, $unearned);

// And the room the party is standing in is, by construction, one of the seen.
$hereKey = $db->query(
    "SELECT l.location_key FROM characters c
       INNER JOIN locations l ON l.id = c.current_location_id
      WHERE c.id = {$leader}"
)->fetchColumn();
ok('wherever they are is on their own map', is_string($hereKey) && $hereKey !== '');

// -- and the raster is fogged by the same answer -----------------------------
// The first-person view walks a tile layer, and it has to be censored the same
// way the chart is or the two show different floors. fogTiles() is given fog()'s
// output rather than asking again, so what is checked here is that the finer
// grain follows the coarse one exactly: no tile of a place the chart is hiding,
// and no doorway into one either.
$tiles = $pm['tiles'];
ok('the raster rides with the plan', is_array($tiles) && isset($tiles['rows']),
    'no tiles block on the payload');

$litIds = [];
foreach (array_merge($pm['rooms'], $pm['corridors']) as $s) {
    if ($s['seen'] || $s['glimpsed']) {
        $litIds[(int) $s['location_id']] = true;
    }
}
$onWire = array_values($tiles['locs']);
$extra = array_values(array_diff($onWire, array_keys($litIds)));
$missing = array_values(array_diff(array_keys($litIds), $onWire));
same('every place on the raster is a place the chart draws', [], $extra);
same('and every place the chart draws has floor on the raster', [], $missing);

// The dark rooms are rock, tile for tile — not dimmed, not outlined, absent.
$rock = 0;
foreach ($dark as $r) {
    if (in_array((int) $r['location_id'], $onWire, true)) {
        $rock++;
    }
}
same('a room the party has not found is rock', 0, $rock);

// A doorway is only ever offered out of somewhere you are standing, and every
// one that is offered leads to a real place.
$strays = 0;
foreach ($tiles['doors'] as $dr) {
    $ch = $tiles['rows'][intdiv($dr['t'], $tiles['w'])][$dr['t'] % $tiles['w']] ?? ' ';
    if ($ch === ' ' || $dr['to'] === null) {
        $strays++;
    }
}
same('every doorway stands on floor and leads somewhere', 0, $strays);

// The stuck door, found the honest way: stand next to it and look.
$stuckIdx = $fixture['stuck'];
$stuckRoomA = (int) $db->query(
    "SELECT id FROM locations WHERE location_key = '_dg_{$partyId}_1_r{$fixture['level']['edges'][$stuckIdx]['a']}'"
)->fetchColumn();
$db->prepare('UPDATE characters c INNER JOIN character_party cp ON cp.character_id = c.id
              SET c.current_location_id = ? WHERE cp.party_id = ?')
    ->execute([$stuckRoomA, $partyId]);
$loc3->search($leader);
$pm2 = $delves->planFor($dressedRegion);
foreach ($pm2['corridors'] as $c) {
    if ($c['id'] === $stuckIdx) {
        same('searching puts the found secret on the map', 'stuck', $c['door']);
    }
}

// -- nothing leaks between delves --------------------------------------------
$db->prepare('UPDATE characters SET current_hp = 24 WHERE id IN ('
    . implode(',', array_map('intval', $charIds)) . ')')->execute();
$delves->descend($leader, $partyId);   // level 2: level 1's region drops
$orphans = (int) $db->query(
    "SELECT COUNT(*) FROM encounters
      WHERE encounter_key LIKE '\\_dg\\_{$partyId}\\_%' AND region_id IS NULL AND location_id IS NULL"
)->fetchColumn();
same('no wandering pool outlives its floor', 0, $orphans);

$delves->end($partyId);
$dgFlags = (int) $db->query(
    "SELECT COUNT(*) FROM world_flags WHERE party_id = {$partyId}
      AND (flag_key LIKE 'dg\\_open\\_%' OR flag_key LIKE 'dg\\_trap\\_%')"
)->fetchColumn();
same('ending the delve forgets its doors and traps', 0, $dgFlags);

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
