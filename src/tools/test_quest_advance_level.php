<?php
/**
 * advance() honours a quest's required_level, like start() does.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_quest_advance_level.php`.
 *
 * advance() creates the party_quests row itself when there is none, and used to
 * do it at any level: a first-level party that picked "That's everything I came
 * with" at Ludo Corse completed the level-4 `the_polite_man` and was paid for
 * it, which then hid Alder Pyke's later greetings. The guard applies only when
 * the party has no row yet — a quest already taken keeps advancing.
 *
 * Builds its own party and removes it. Safe against a database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db(); $qs = new QuestService($db);
$pass=0; $fail=0;
function ok(string $n, bool $c, string $d='') { global $pass,$fail;
  if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n".($d?"\n         $d":'')."\n"; } }

$db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
    (SELECT id FROM modules WHERE module_key='rivermark'), 'advance level probe')")->execute();
$pid = (int) $db->lastInsertId();
$rows = fn (string $key): int => (int) $db->query("SELECT COUNT(*) FROM party_quests pq
    JOIN quests q ON q.id=pq.quest_id WHERE pq.party_id=$pid AND q.quest_key='$key'")->fetchColumn();
try {
    // No characters, so leaderLevel() is 1 — below the_polite_man's 4.
    $stage = (string) $db->query("SELECT s.stage_key FROM quest_stages s JOIN quests q ON q.id=s.quest_id
        WHERE q.quest_key='the_polite_man' AND s.is_terminal=1 ORDER BY s.sort_order LIMIT 1")->fetchColumn();
    $r = $qs->advance($pid, 'the_polite_man', $stage);
    ok('a level-1 party cannot advance into a level-4 quest', $rows('the_polite_man') === 0);
    ok('and it says why rather than throwing',
       (bool) array_filter($r['notes'] ?? [], fn($n) => str_contains($n, 'higher level')),
       json_encode($r['notes'] ?? []));
    ok('and no XP or stage effects were returned', empty($r['quests_advanced']));

    // A quest at or under the party's level still starts by being advanced.
    $first = (string) $db->query("SELECT s.stage_key FROM quest_stages s JOIN quests q ON q.id=s.quest_id
        WHERE q.quest_key='cellar_rats' ORDER BY s.sort_order LIMIT 1")->fetchColumn();
    $r = $qs->advance($pid, 'cellar_rats', $first);
    ok('a quest within the party level is still started by advance()', $rows('cellar_rats') === 1);
} finally {
    foreach (['party_quest_stages','party_quests','world_flags'] as $t) {
        $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$pid]);
    }
    $db->prepare('DELETE FROM parties WHERE id=?')->execute([$pid]);
}
echo "\n$pass passed, $fail failed\n";
exit($fail?1:0);
