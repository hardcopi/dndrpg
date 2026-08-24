<?php
/**
 * Arriving at a room, as a way of getting on with a generated quest.
 *
 * The rule under test is QuestService::arriveAt, and it is worth its own file
 * because getting it wrong is quiet. The first version advanced to any unpassed
 * stage whose target was this room, which let a party walk into the LAST room
 * first and finish the errand without doing it — found by walking a delve, not
 * by reading the code.
 *
 * Hermetic: it writes its own quest and its own party row rather than delving,
 * so it needs no map service and leaves nothing behind.
 *
 *   docker compose exec -T php php /var/www/html/tools/test_delve_quest.php
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

$db = db();
$svc = new QuestService($db);

// Three rooms to stand in, and a party to stand in them. Written into the
// free-play world so the rows are legal, and removed at the end.
$regionId = (int) $db->query(
    "SELECT r.id FROM regions r
       INNER JOIN modules m ON m.id = r.module_id
      WHERE m.module_key = '" . FREE_PLAY_MODULE . "' LIMIT 1"
)->fetchColumn();
if ($regionId <= 0) {
    echo "No free-play region — run the free-play migration first.\n";
    exit(1);
}

$partyId = (int) $db->query('SELECT id FROM parties ORDER BY id LIMIT 1')->fetchColumn();
if ($partyId <= 0) {
    echo "No party to test with.\n";
    exit(1);
}

$key = '_dg_q_test_' . getmypid();
$rooms = [];
$insertLoc = $db->prepare(
    'INSERT INTO locations (location_key, region_id, name, description, location_type, sort_order)
     VALUES (?, ?, ?, ?, ?, ?)'
);
foreach (['alpha', 'beta', 'gamma'] as $i => $name) {
    $lk = $key . '_' . $name;
    $insertLoc->execute([$lk, $regionId, 'Test ' . $name, 'A test room.', 'room', 9000 + $i]);
    $rooms[] = (int) $db->lastInsertId();
}

$db->prepare(
    'INSERT INTO quests (quest_key, title, description, act, on_job_board, required_level,
                         reward_xp, reward_gold, is_active)
     VALUES (?, ?, ?, 1, 0, 1, 0, 0, 1)'
)->execute([$key, 'A test errand', 'Walk it in order.']);
$questId = (int) $db->lastInsertId();

$insertStage = $db->prepare(
    'INSERT INTO quest_stages (quest_id, stage_key, title, objective, journal_entry,
                               target_location_id, is_terminal, outcome, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, \'success\', ?)'
);
foreach ([['s1', 0, 0], ['s2', 1, 0], ['s3', 2, 1]] as [$sk, $room, $terminal]) {
    $insertStage->execute([$questId, $sk, $sk, "Go to {$sk}", "Went to {$sk}", $rooms[$room], $terminal, (int) substr($sk, 1) * 10]);
}

$at = static function () use ($db, $partyId, $questId): string {
    $r = $db->query("SELECT COALESCE(s.stage_key,'-') FROM party_quests pq
                     LEFT JOIN quest_stages s ON s.id = pq.current_stage_id
                     WHERE pq.party_id = {$partyId} AND pq.quest_id = {$questId}")->fetchColumn();
    return $r === false ? '(none)' : (string) $r;
};
$status = static function () use ($db, $partyId, $questId): string {
    $r = $db->query("SELECT status FROM party_quests
                     WHERE party_id = {$partyId} AND quest_id = {$questId}")->fetchColumn();
    return $r === false ? '(none)' : (string) $r;
};

try {
    echo "== the errand is a route ==\n";
    $svc->advance($partyId, $key, 's1');
    ok('it starts at the first stage', $at() === 's1', $at());

    // Two stages ahead: the room the errand ENDS in, walked into first. This is
    // the bug this file exists for — it used to finish the quest from here.
    $svc->arriveAt($partyId, $rooms[2]);
    ok('the last room does not finish it early', $at() === 's1' && $status() === 'active',
        $at() . ' / ' . $status());

    // One stage back: where they already are. Nothing to advance to.
    $svc->arriveAt($partyId, $rooms[0]);
    ok('nor does the room it is already in', $at() === 's1', $at());

    // The next stage's room, in turn.
    $svc->arriveAt($partyId, $rooms[1]);
    ok('the next room advances it', $at() === 's2', $at());

    $svc->arriveAt($partyId, $rooms[0]);
    ok('and going back does not undo that', $at() === 's2', $at());

    $svc->arriveAt($partyId, $rooms[2]);
    ok('the last room ends it, once it is the next one', $at() === 's3' && $status() === 'completed',
        $at() . ' / ' . $status());

    $svc->arriveAt($partyId, $rooms[2]);
    ok('and a finished errand stays finished', $status() === 'completed', $status());

    echo "\n== and it leaves authored quests alone ==\n";
    $authored = $db->query(
        "SELECT q.quest_key FROM quests q
           INNER JOIN quest_stages s ON s.quest_id = q.id
          WHERE q.quest_key NOT LIKE '\\_dg\\_q\\_%' AND s.target_location_id IS NOT NULL
          LIMIT 1"
    )->fetchColumn();
    if ($authored === false) {
        ok('no authored quest targets a location on this install — nothing to protect', true);
    } else {
        $before = $db->query("SELECT COUNT(*) FROM party_quests pq
                              INNER JOIN quests q ON q.id = pq.quest_id
                              WHERE q.quest_key = " . $db->quote((string) $authored))->fetchColumn();
        $where = (int) $db->query(
            "SELECT s.target_location_id FROM quest_stages s
               INNER JOIN quests q ON q.id = s.quest_id
              WHERE q.quest_key = " . $db->quote((string) $authored) . "
                AND s.target_location_id IS NOT NULL LIMIT 1"
        )->fetchColumn();
        $svc->arriveAt($partyId, $where);
        $after = $db->query("SELECT COUNT(*) FROM party_quests pq
                             INNER JOIN quests q ON q.id = pq.quest_id
                             WHERE q.quest_key = " . $db->quote((string) $authored))->fetchColumn();
        ok('standing on an authored quest\'s target starts nothing', $before === $after,
            "{$before} -> {$after} for {$authored}");
    }
} finally {
    $db->prepare('DELETE FROM party_quest_stages WHERE quest_id = ?')->execute([$questId]);
    $db->prepare('DELETE FROM party_quests WHERE quest_id = ?')->execute([$questId]);
    $db->prepare('DELETE FROM quest_stages WHERE quest_id = ?')->execute([$questId]);
    $db->prepare('DELETE FROM quests WHERE id = ?')->execute([$questId]);
    $db->prepare('DELETE FROM locations WHERE location_key LIKE ?')->execute([$key . '_%']);
}

echo "\n----------------------------------------------------\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
