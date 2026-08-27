<?php
/**
 * The chest in the room: the lid, the lock, the mechanism, and the loot behind it.
 *
 *   docker compose exec -T php php /var/www/html/tools/test_furnishing.php
 *
 * WHAT IS ACTUALLY UNDER TEST. Three things, and only the first is obvious:
 *
 *  1. The verbs — a shut lid refuses to be forced when it is not fastened, a
 *     fastened one refuses to be opened, a found trap can be disarmed and an
 *     unfound one cannot.
 *  2. The GATE. Ground loot in a furnished room is inside the thing. This is
 *     the half that can rot silently: the furnishing is drawn, the menu works,
 *     the party opens it — and the items were visible in the panel the whole
 *     time because somebody moved the itemsAt() call. A test that only drove
 *     the engine would pass through that.
 *  3. The SEAM. FurnishingEngine reads what the dungeon is out of the stored
 *     level and what has happened to it out of WorldState. Standing a party in
 *     a room whose key does not match their delve must answer null rather than
 *     answering from the floor they are on.
 *
 * Hermetic: it writes its own delve row, its own room and its own level_json
 * rather than descending, so it needs no map service and no stair, and it
 * cleans up after itself. The level is DungeonGen's own output with one room's
 * furnishing overwritten, so the SHAPE under test is the real one.
 */

declare(strict_types=1);

require_once '/var/www/html/app/bootstrap.php';

$passed = 0;
$failed = 0;

function ok(string $what, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok   {$what}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$what}" . ($detail === '' ? '' : "\n         {$detail}") . "\n";
}

/** Run something that ought to be refused, and say whether it was. */
function refused(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
}

$db = db();

// --- somewhere to stand, and somebody to stand there ----------------------
$regionId = (int) $db->query(
    "SELECT r.id FROM regions r
       INNER JOIN modules m ON m.id = r.module_id
      WHERE m.module_key = '" . FREE_PLAY_MODULE . "' LIMIT 1"
)->fetchColumn();
if ($regionId <= 0) {
    echo "No free-play region — run the free-play migration first.\n";
    exit(1);
}
// A party that actually has somebody in it. `ORDER BY id LIMIT 1` found the
// oldest row on this database, which has no members at all — a fixture picked
// by age rather than by fitness, and the test failed on the database rather
// than on the code.
$row = $db->query(
    'SELECT cp.party_id, MIN(cp.character_id) AS character_id
       FROM character_party cp
       INNER JOIN characters c ON c.id = cp.character_id AND c.is_active = 1
      GROUP BY cp.party_id
      ORDER BY cp.party_id
      LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$partyId = (int) ($row['party_id'] ?? 0);
$charId = (int) ($row['character_id'] ?? 0);
if ($partyId <= 0 || $charId <= 0) {
    echo "No party with a character in it to test with.\n";
    exit(1);
}

// State to put back. This test moves a real character and writes a real delve
// row, so everything it touched is restored in the shutdown below.
$stmt = $db->prepare('SELECT current_location_id, current_hp FROM characters WHERE id = ?');
$stmt->execute([$charId]);
$before = $stmt->fetch(PDO::FETCH_ASSOC);
$priorDelve = (new DelveEngine($db))->current($partyId);

$depth = 2;
$seed = 4242;
$level = DungeonGen::generate($seed, $depth);

// One room, made certainly furnished, certainly locked and certainly rigged —
// the generator's own record with its three uncertain fields pinned, so what is
// under test is the engine and not today's dice.
$roomId = null;
foreach ($level['rooms'] as $i => $r) {
    if (!empty($r['furnishing'])) {
        $roomId = (int) $r['id'];
        $level['rooms'][$i]['furnishing']['locked'] = true;
        $level['rooms'][$i]['furnishing']['trapped'] = true;
        $level['rooms'][$i]['furnishing']['dc'] = 12;
        $level['rooms'][$i]['furnishing']['trap'] = DungeonGen::TRAPS['middle'][0];
        $level['rooms'][$i]['furnishing']['trap']['dc'] = 5;   // findable, so the test can find it
        break;
    }
}
ok('the generator furnished a room on seed ' . $seed, $roomId !== null);
if ($roomId === null) {
    exit(1);
}

$roomKey = DelveEngine::roomKey($partyId, $depth, $roomId);
$db->prepare('DELETE FROM locations WHERE location_key = ?')->execute([$roomKey]);
$db->prepare(
    'INSERT INTO locations (location_key, region_id, name, description, location_type, sort_order)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$roomKey, $regionId, 'Test chamber', 'A room with a box in it.', 'dungeon', 9000]);
$roomLocId = (int) $db->lastInsertId();

// A thing in it, so the gate has something to hide.
$itemId = (int) $db->query('SELECT id FROM items ORDER BY id LIMIT 1')->fetchColumn();
$db->prepare('INSERT INTO location_items (location_id, item_id) VALUES (?, ?)')
    ->execute([$roomLocId, $itemId]);
$locItemId = (int) $db->lastInsertId();
// SCOPED TO THIS TEST'S OWN ROW, not to the party.
//
// This was `DELETE ... WHERE party_id = ?` — the whole party's record of every
// item it has ever picked up, deleted so that one fixture item would read as
// untaken. That is player progress, on a real party borrowed as a fixture, and
// a test that needs a clean slate must make one rather than clear somebody's.
// The location_item_id is this test's own; nothing else can match it.
$db->prepare('DELETE FROM party_items_taken WHERE party_id = ? AND location_item_id = ?')
    ->execute([$partyId, $locItemId]);

$db->prepare('DELETE FROM dungeon_delves WHERE party_id = ?')->execute([$partyId]);
$db->prepare(
    'INSERT INTO dungeon_delves (party_id, seed, depth, region_id, level_json)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$partyId, $seed, $depth, $regionId, json_encode($level, JSON_UNESCAPED_SLASHES)]);

$db->prepare('UPDATE characters SET current_location_id = ?, current_hp = 40 WHERE id = ?')
    ->execute([$roomLocId, $charId]);

$world = new WorldState($db);
$world->clear($partyId, DelveEngine::furnishingFlag($roomKey));
$world->clear($partyId, DelveEngine::furnishingTrapFlag($roomKey));

$eng = new FurnishingEngine($db);
$loc = new LocationEngine($db);

// --- the seam --------------------------------------------------------------
$found = (new DelveEngine($db))->furnishingAt($partyId, $roomLocId);
ok('the room key resolves to the level\'s own furnishing', $found !== null
    && (int) $found['room'] === $roomId);
ok('an authored location has no furnishing',
    (new DelveEngine($db))->furnishingAt($partyId, 1) === null);

// --- the gate --------------------------------------------------------------
$state = $loc->getState($charId, $partyId);
ok('the payload carries the furnishing', !empty($state['location']['furnishing']));
ok('it does not say whether it is locked',
    !array_key_exists('locked', $state['location']['furnishing'] ?? []));
ok('the loot is behind the lid', ($state['location']['items'] ?? ['x']) === []);

$looked = $loc->search($charId);
$joined = implode("\n", $looked['messages'] ?? []);
ok('search does not name loot behind a shut lid', strpos($joined, 'worth taking') === false);
ok('it points at the furnishing instead', strpos($joined, 'put away in ') !== false);
ok('and does not hand the items over', ($looked['items'] ?? ['x']) === []);

// --- the lock --------------------------------------------------------------
ok('a fastened lid refuses to be simply opened', refused(fn () => $eng->open($charId)));
$menu = $eng->menu($charId);
$acts = array_column($menu['options'], 'enabled', 'act');
ok('the menu offers Open and disables it', array_key_exists('open', $acts) && $acts['open'] === false);
ok('the menu offers Force', ($acts['force'] ?? null) === true);
ok('an unfound trap cannot be disarmed', refused(fn () => $eng->disarm($charId)));

// --- looking it over -------------------------------------------------------
// DC 5 against a d20 plus a modifier: it can still miss, so it is given the
// tries a party would have. What is under test is that finding it is POSSIBLE
// and that it changes the menu, not the odds of one roll.
for ($i = 0; $i < 40; $i++) {
    $look = $eng->inspect($charId);
    if (!empty($look['found'])) {
        break;
    }
}
ok('the trap can be found by looking', !empty($look['found']));
// A FRESH READER. WorldState caches per instance, and the copy above was
// filled before inspect() wrote anything — asking it would be asking what the
// flags were a moment ago. Harmless in the game, where every request builds its
// own, and exactly the kind of thing a test holding one object all the way
// through will read as a bug in the engine.
$fresh = new WorldState($db);
ok('finding it writes the furnishing\'s own flag, not the room\'s',
    $fresh->get($partyId, DelveEngine::furnishingTrapFlag($roomKey)) === 'found'
    && $fresh->get($partyId, DelveEngine::trapFlag($roomKey)) === null);
$acts = array_column($eng->menu($charId)['options'], 'enabled', 'act');
ok('and the menu now offers Disarm instead of another look',
    ($acts['disarm'] ?? null) === true && !array_key_exists('inspect', $acts));

// --- forcing it ------------------------------------------------------------
$req = $eng->force($charId);
ok('forcing offers a check at the lid\'s own DC',
    (int) ($req['check']['dc'] ?? 0) === 12);
$checkId = (string) ($req['check']['id'] ?? $req['check']['check_id'] ?? '');
ok('the check has an id to resolve', $checkId !== '');

$out = $eng->openResolve($charId, $checkId, []);
ok('resolving it answers with an outcome', isset($out['opened']));

// However the die fell, drive the rest from a known state: open it outright.
$world->set($partyId, DelveEngine::furnishingFlag($roomKey), 'open');

// --- what an open one changes ---------------------------------------------
$state = $loc->getState($charId, $partyId);
ok('the loot is reachable once it is open', count($state['location']['items'] ?? []) === 1);
ok('and the payload says so', !empty($state['location']['furnishing']['open']));
ok('an open one refuses to be forced again', refused(fn () => $eng->force($charId)));
ok('opening an open one is not an error', ($eng->open($charId))['opened'] === false);

// --- the trap fires on whoever opened it ----------------------------------
$world->clear($partyId, DelveEngine::furnishingFlag($roomKey));
$world->clear($partyId, DelveEngine::furnishingTrapFlag($roomKey));
$db->prepare('UPDATE characters SET current_hp = 40 WHERE id = ?')->execute([$charId]);
// Unfastened this time, so open() itself is the thing that lifts the lid.
$level['rooms'][array_search($roomId, array_column($level['rooms'], 'id'), true)]['furnishing']['locked'] = false;
$db->prepare('UPDATE dungeon_delves SET level_json = ? WHERE party_id = ?')
    ->execute([json_encode($level, JSON_UNESCAPED_SLASHES), $partyId]);

$out = $eng->open($charId);
ok('an unfastened lid opens', !empty($out['opened']));
ok('and the mechanism under it goes off', !empty($out['sprung']));
ok('which is an event the client can animate',
    ($out['events'][0]['type'] ?? '') === 'trap');
$stmt = $db->prepare('SELECT current_hp FROM characters WHERE id = ?');
$stmt->execute([$charId]);
ok('and it costs somebody hit points', (int) $stmt->fetchColumn() < 40);
ok('a sprung one does not go off twice', empty(($eng->open($charId))['sprung']));

// --- a stale key -----------------------------------------------------------
// The same room number on a floor the party has left. `furnishingAt` reads the
// depth out of the key and must refuse rather than answer from the floor they
// are on now — otherwise a location row that outlived its floor would open a
// chest on a different level.
$staleKey = DelveEngine::roomKey($partyId, $depth + 1, $roomId);
$db->prepare('UPDATE locations SET location_key = ? WHERE id = ?')->execute([$staleKey, $roomLocId]);
ok('a room key from another floor answers nothing',
    (new DelveEngine($db))->furnishingAt($partyId, $roomLocId) === null);

// --- put it all back -------------------------------------------------------
$db->prepare('DELETE FROM location_items WHERE location_id = ?')->execute([$roomLocId]);
$db->prepare('DELETE FROM locations WHERE id = ?')->execute([$roomLocId]);
$db->prepare('DELETE FROM dungeon_delves WHERE party_id = ?')->execute([$partyId]);
if ($priorDelve !== null) {
    $db->prepare(
        'INSERT INTO dungeon_delves (party_id, seed, depth, region_id, mouth_location_id, level_json)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $partyId, $priorDelve['seed'], $priorDelve['depth'], $priorDelve['region_id'],
        $priorDelve['mouth_location_id'], $priorDelve['level_json'],
    ]);
}
$db->prepare('UPDATE characters SET current_location_id = ?, current_hp = ? WHERE id = ?')
    ->execute([$before['current_location_id'], $before['current_hp'], $charId]);
$world->clear($partyId, DelveEngine::furnishingFlag($roomKey));
$world->clear($partyId, DelveEngine::furnishingTrapFlag($roomKey));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
