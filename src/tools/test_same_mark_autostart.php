<?php
/**
 * `the_same_mark` opens by itself on the second sighting of the mark.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_same_mark_autostart.php`.
 *
 * It used to open only if the player went back to Alder Pyke, whom nothing else
 * names, and starting it there entered `two_places` and left it in the same
 * click. Effects now starts it when the second of the five sighting flags is
 * set — silently below the quest's level, and never twice.
 *
 * Builds its own party and removes it. Safe against a database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db(); $fx = new Effects($db); $qs = new QuestService($db, $fx);
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass,$fail;
  if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n".($d?"\n         $d":'')."\n"; } }

$db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
    (SELECT id FROM modules WHERE module_key='rivermark'), 'same mark probe')")->execute();
$pid = (int) $db->lastInsertId();
$cid = 0;
$flag = fn (string $k) => $fx->apply($pid, [['set_flag' => $k]]);
$status = fn () => $qs->statusFor($pid, 'the_same_mark');
$stage = fn (): ?string => ($v = $db->query("SELECT s.stage_key FROM party_quests pq
    JOIN quests q ON q.id=pq.quest_id JOIN quest_stages s ON s.id=pq.current_stage_id
    WHERE pq.party_id=$pid AND q.quest_key='the_same_mark'")->fetchColumn()) === false ? null : (string) $v;
try {
    // No characters, so the leader is level 1 — below the quest's 2.
    $flag('grain_stamp_seen');
    ok('one sighting opens nothing', $status() === null);
    $r = $flag('mark_seen_paper');
    ok('two sightings at level 1 stay silent', $status() === null && empty($r['quests_started']));

    // Level the leader up; the next sighting re-checks.
    $db->prepare("INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
            constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
            proficiency_bonus, is_active)
        VALUES (?, 'Human', 'Fighter', 2, 300, 12, 12, 12, 12, 12, 12, 20, 20, 14, 2, 1)")
        ->execute(['_same_mark_probe_' . bin2hex(random_bytes(3))]);
    $cid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,1)')->execute([$pid, $cid]);
    $db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')->execute([$cid, $pid]);

    $r = $flag('mark_seen_book');
    ok('the next sighting at level 2 starts it', $status() === 'active');
    ok('and says so', in_array('the_same_mark', $r['quests_started'], true), json_encode($r['quests_started']));
    ok('at its first stage, `two_places`', $stage() === 'two_places', (string) $stage());

    // Setting more flags never starts it again or moves it.
    $qs->advance($pid, 'the_same_mark', 'asked_the_chainman');
    $r = $flag('mark_seen_stone');
    ok('a later sighting leaves a started quest alone',
       $stage() === 'asked_the_chainman' && empty($r['quests_started']), (string) $stage());
    $qs->advance($pid, 'the_same_mark', 'let_it_alone');
    $flag('mark_seen_plate');
    ok('and a finished one', $status() === 'completed');

    // Flags that are not sightings never trigger it, and one flag alone is not two.
    $db->prepare('DELETE FROM party_quest_stages WHERE party_id=?')->execute([$pid]);
    $db->prepare('DELETE FROM party_quests WHERE party_id=?')->execute([$pid]);
    $db->prepare('DELETE FROM world_flags WHERE party_id=?')->execute([$pid]);
    $fx2 = new Effects($db);
    $fx2->apply($pid, [['set_flag' => 'met_pyke']]);
    $fx2->apply($pid, [['set_flag' => 'mark_seen_plate']]);
    $fx2->apply($pid, [['set_flag' => 'mark_seen_plate']]);
    ok('the same flag twice is still one sighting',
       $qs->statusFor($pid, 'the_same_mark') === null);
    $fx2->apply($pid, [['set_flag' => 'grain_stamp_seen']]);
    ok('any two of the five open it', $qs->statusFor($pid, 'the_same_mark') === 'active');
} finally {
    foreach (['party_quest_stages','party_quests','world_flags','character_party'] as $t) {
        $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$pid]);
    }
    $db->prepare('DELETE FROM parties WHERE id=?')->execute([$pid]);
    if ($cid) { $db->prepare('DELETE FROM characters WHERE id=?')->execute([$cid]); }
}
echo "\n$pass passed, $fail failed\n";
exit($fail?1:0);
