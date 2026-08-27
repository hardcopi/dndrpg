<?php
/**
 * The Proving House watch spine: talk, one floor, sandbox. No board.
 *
 * Hermetic: builds its own party in free-play and leaves the authored quests
 * in place (they are content). Restores the inn's job-board flag so a live
 * Proving House is not left with a board this spine does not use.
 *
 *   docker compose exec -T php php /var/www/html/tools/test_watch.php
 */
declare(strict_types=1);

require_once '/var/www/html/app/bootstrap.php';

putenv('RPG_MAPGEN_URL=');

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

function threw(string $what, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        ok($what, false, 'nothing was thrown');
    } catch (Throwable $e) {
        ok($what, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
    }
}

function npcByKey(array $npcs, string $key): ?array
{
    foreach ($npcs as $n) {
        if (($n['npc_key'] ?? '') === $key) {
            return $n;
        }
    }
    return null;
}

function choiceIndex(array $node, string $needle): int
{
    foreach ($node['choices'] ?? [] as $i => $c) {
        if (str_contains((string) ($c['label'] ?? ''), $needle)) {
            return (int) $i;
        }
    }
    throw new RuntimeException('no choice matching ' . $needle . ' in ' . json_encode(array_column($node['choices'] ?? [], 'label')));
}

$db = db();
$qs = new QuestService($db);
$delves = new DelveEngine($db);
$locs = new LocationEngine($db);
$dlg = new DialogEngine($db);

$moduleId = (int) $db->query(
    "SELECT id FROM modules WHERE module_key = '" . FREE_PLAY_MODULE . "'"
)->fetchColumn();
$innId = (int) $db->query(
    "SELECT id FROM locations WHERE location_key = '_freeplay_yard'"
)->fetchColumn();
$pitId = (int) $db->query(
    "SELECT id FROM locations WHERE location_key = '_freeplay_cellar'"
)->fetchColumn();

ok('free-play module is loaded', $moduleId > 0);
ok('the Proving House is authored', $innId > 0);
ok('the Pit is authored', $pitId > 0);
if ($moduleId <= 0 || $innId <= 0 || $pitId <= 0) {
    echo "Free-play inn is missing — run the freeplay-inn migration first.\n";
    exit(1);
}

$boardWas = (int) $db->query(
    "SELECT has_job_board FROM locations WHERE id = {$innId}"
)->fetchColumn();

$db->prepare('INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, ?)')
    ->execute([$moduleId, '__watch_probe']);
$partyId = (int) $db->lastInsertId();

$db->prepare(
    'INSERT INTO characters (name, race, class, level, strength, dexterity, constitution,
        intelligence, wisdom, charisma, max_hp, current_hp, armor_class, speed,
        proficiency_bonus, current_location_id)
     VALUES ("__watch_Wren", "Human", "Wizard", 1, 10,12,12,14,12,10, 8,8, 12, 30, 2, ?)'
)->execute([$innId]);
$charId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO character_party (party_id, character_id) VALUES (?, ?)')
    ->execute([$partyId, $charId]);
$db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')
    ->execute([$charId, $partyId]);

$cleanup = static function () use ($db, $partyId, $charId, $delves, $innId, $boardWas): void {
    try {
        $delves->end($partyId, false);
    } catch (Throwable $e) {
        // continue
    }
    $db->prepare("DELETE FROM regions WHERE region_key LIKE ?")->execute(["_dg_{$partyId}_%"]);
    $db->prepare('DELETE FROM dungeon_delves WHERE party_id = ?')->execute([$partyId]);
    $db->prepare('DELETE FROM party_quest_stages WHERE party_id = ?')->execute([$partyId]);
    $db->prepare('DELETE FROM party_quests WHERE party_id = ?')->execute([$partyId]);
    $db->prepare('DELETE FROM world_flags WHERE party_id = ?')->execute([$partyId]);
    $db->prepare('DELETE FROM character_inventory WHERE character_id = ?')->execute([$charId]);
    $db->prepare('DELETE FROM character_party WHERE party_id = ?')->execute([$partyId]);
    $db->prepare('DELETE FROM characters WHERE id = ?')->execute([$charId]);
    $db->prepare('DELETE FROM parties WHERE id = ?')->execute([$partyId]);
    $db->prepare('UPDATE npcs SET location_id = NULL WHERE npc_key = ?')->execute(['_fp_cousin']);
    $db->prepare('UPDATE locations SET has_job_board = ? WHERE id = ?')->execute([$boardWas ? 1 : 0, $innId]);
};
register_shutdown_function($cleanup);

echo "== the inn has no board ==\n";
$db->prepare('UPDATE locations SET has_job_board = 0 WHERE id = ?')->execute([$innId]);
$flag = (int) $db->query("SELECT has_job_board FROM locations WHERE id = {$innId}")->fetchColumn();
ok('the inn row has no board', $flag === 0);
$scene = $locs->getState($charId, $partyId);
ok('the location payload withholds the board', empty($scene['location']['job_board']));

echo "\n== Oddvar wears the offer before anyone talks ==\n";
$odd = npcByKey($scene['location']['npcs'] ?? [], '_fp_odd');
$hessa = npcByKey($scene['location']['npcs'] ?? [], '_fp_hessa');
$brenna = npcByKey($scene['location']['npcs'] ?? [], '_fp_brenna');
ok('Oddvar is in the common room', $odd !== null);
ok('Oddvar\'s mark is offer', ($odd['quest_mark'] ?? null) === 'offer',
    json_encode($odd['quest_mark'] ?? null));
ok('Hessa has no mark', ($hessa['quest_mark'] ?? null) === null,
    json_encode($hessa['quest_mark'] ?? 'missing'));
ok('Brenna has no mark', ($brenna['quest_mark'] ?? null) === null,
    json_encode($brenna['quest_mark'] ?? 'missing'));

echo "\n== the Pit refuses Down until the job is taken ==\n";
$db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$pitId, $charId]);
$st = $delves->status($charId, $partyId);
ok('status is returned at the Pit', $st !== null);
ok('can_descend is false before start_quest', empty($st['can_descend']));
ok('a hint points them at Oddvar', is_string($st['descend_hint'] ?? null) && $st['descend_hint'] !== '',
    (string) ($st['descend_hint'] ?? ''));
ok('can_deeper is false', empty($st['can_deeper']));
threw('and descend itself is refused',
    static fn () => $delves->descend($charId, $partyId),
    'Not without a reason');

echo "\n== talking starts the quest; the board is not required ==\n";
$db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$innId, $charId]);
$hail = $dlg->node($partyId, '_fp_odd');
ok('Oddvar will talk about work', str_contains(json_encode($hail['choices'] ?? []), 'Need anything from below?'),
    json_encode(array_column($hail['choices'] ?? [], 'label')));
$offer = $dlg->choose($partyId, '_fp_odd', 'hail', choiceIndex($hail, 'Need anything from below?'));
$jobNode = $offer['node'] ?? [];
ok('the crate job is offered in talk', str_contains((string) ($jobNode['text'] ?? ''), 'Crate went down'),
    (string) ($jobNode['text'] ?? ''));
$taken = $dlg->choose($partyId, '_fp_odd', 'notice_crate', choiceIndex($jobNode, "I'll bring it up."));
ok('talking starts the crate job', $qs->isActiveFor($partyId, 'fp_watch_crate'),
    json_encode($taken['result']['quests_started'] ?? null));
ok('no job_read flag was required',
    !(new WorldState($db))->isSet($partyId, 'job_read:fp_watch_crate'));

$scene = $locs->getState($charId, $partyId);
$odd = npcByKey($scene['location']['npcs'] ?? [], '_fp_odd');
ok('the offer mark drops once the job is taken', ($odd['quest_mark'] ?? null) === null,
    json_encode($odd['quest_mark'] ?? 'missing'));

echo "\n== after start, Down writes the crate floor ==\n";
$db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$pitId, $charId]);
$st = $delves->status($charId, $partyId);
ok('can_descend is true once the job is taken', !empty($st['can_descend']));

$r = $delves->descend($charId, $partyId);
ok('descend succeeds', !empty($r['ok']));
ok('the floor is small (tutorial)', (int) $r['rooms'] === 3, (string) ($r['rooms'] ?? ''));

$delve = $delves->current($partyId);
ok('a delve row exists', $delve !== null);
$level = json_decode((string) ($delve['level_json'] ?? ''), true) ?: [];
ok('the stored floor is the crate job', ($level['watch_job'] ?? '') === 'fp_watch_crate');

$discRoom = null;
foreach ($level['rooms'] ?? [] as $room) {
    if (($room['place_items'][0] ?? '') === 'holy_symbol') {
        $discRoom = $room;
        break;
    }
}
ok('the Sun-Disc is placed on the floor', $discRoom !== null);
ok('behind a furnishing', !empty($discRoom['furnishing']));
ok('and the lid is shut (unlocked, untrapped)',
    !empty($discRoom['furnishing'])
    && empty($discRoom['furnishing']['locked'])
    && empty($discRoom['furnishing']['trapped']));

$regionId = (int) ($delve['region_id'] ?? 0);
$itemId = (int) $db->query("SELECT id FROM items WHERE item_key = 'holy_symbol'")->fetchColumn();
$placed = (int) $db->query(
    "SELECT COUNT(*) FROM location_items li
       INNER JOIN locations l ON l.id = li.location_id
      WHERE l.region_id = {$regionId} AND li.item_id = {$itemId}"
)->fetchColumn();
ok('the disc is a location_items row', $placed === 1, (string) $placed);

$fights = (int) $db->query(
    "SELECT COUNT(*) FROM encounters e
       INNER JOIN locations l ON l.id = e.location_id
      WHERE l.region_id = {$regionId} AND e.is_random = 0"
)->fetchColumn();
ok('the tutorial has no standing fight', $fights === 0, (string) $fights);

$stDown = $delves->status($charId, $partyId);
ok('can_deeper stays false on the crate floor', empty($stDown['can_deeper']));

echo "\n== the turn-in mark lights when the objective is done ==\n";
$db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$innId, $charId]);
$scene = $locs->getState($charId, $partyId);
$odd = npcByKey($scene['location']['npcs'] ?? [], '_fp_odd');
ok('no turn-in mark without the disc', ($odd['quest_mark'] ?? null) === null,
    json_encode($odd['quest_mark'] ?? 'missing'));

$db->prepare(
    'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped)
     VALUES (?, ?, 1, 0)'
)->execute([$charId, $itemId]);
$scene = $locs->getState($charId, $partyId);
$odd = npcByKey($scene['location']['npcs'] ?? [], '_fp_odd');
ok('Oddvar\'s mark is turnin once the disc is in hand', ($odd['quest_mark'] ?? null) === 'turnin',
    json_encode($odd['quest_mark'] ?? null));

echo "\n== after the sandbox flag, descend is free ==\n";
$delves->end($partyId, false);
$db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$pitId, $charId]);
// Sandbox is what job 6's terminal stage sets, after the watch jobs are done.
// An active crate would still draw the tutorial; clear it the way a turn-in would.
$db->prepare("DELETE FROM party_quest_stages WHERE party_id = ?")->execute([$partyId]);
$db->prepare("DELETE FROM party_quests WHERE party_id = ?")->execute([$partyId]);
(new WorldState($db))->set($partyId, WatchJobs::SANDBOX_FLAG);
$st = $delves->status($charId, $partyId);
ok('sandbox unlocks can_descend', !empty($st['can_descend']));
$r2 = $delves->descend($charId, $partyId);
ok('sandbox descend succeeds', !empty($r2['ok']));
ok('and it is not the three-room crate', (int) $r2['rooms'] > 3, (string) ($r2['rooms'] ?? ''));
$delve2 = $delves->current($partyId);
$level2 = json_decode((string) ($delve2['level_json'] ?? ''), true) ?: [];
ok('sandbox floors are not tagged as a watch job', empty($level2['watch_job']));

echo "\n----------------------------------------------------\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
