<?php
/**
 * A party cannot take another module's work.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_quest_module.php`.
 *
 * QuestService::postable() filters the job BOARD by module, which was enough
 * while the board was the only way across — and it was not. CombatEngine's
 * defeat path woke a beaten party at a hardcoded Rivermark location, and from
 * there they talked to a Rivermark companion and took `kessa_debt` into an Old
 * City save, where its target is a location no exit reaches. A filter on one
 * door is not a guard; start() is the guard, and this is its test.
 *
 * Builds its own parties and removes them. Safe against a database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db(); $qs = new QuestService($db);
$pass=0; $fail=0;
function ok(string $n, bool $c, string $d='') { global $pass,$fail;
  if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n".($d?"\n         $d":'')."\n"; } }

$made=[];
foreach (['old_city','rivermark'] as $mod) {
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
        (SELECT id FROM modules WHERE module_key=?), 'guard probe')")->execute([$mod]);
    $made[$mod] = (int) $db->lastInsertId();
}
try {
    // The exact quest that leaked into the save.
    $r = $qs->start($made['old_city'], 'kessa_debt');
    $took = (int) $db->query("SELECT COUNT(*) FROM party_quests pq JOIN quests q ON q.id=pq.quest_id
        WHERE pq.party_id={$made['old_city']} AND q.quest_key='kessa_debt'")->fetchColumn();
    ok('an Old City party cannot take kessa_debt', $took === 0);
    ok('and it says why rather than failing silently',
       (bool) array_filter($r['notes'] ?? [], fn($n) => str_contains($n, 'another module')),
       json_encode($r['notes'] ?? []));

    // Same module: it must get PAST the module guard. These probe parties have
    // no characters, so leaderLevel() is 0 and the level gate throws after it —
    // which is the right behaviour and not what is under test here.
    $reachedLevelGate = function (int $pid, string $key) use ($qs): bool {
        try {
            $r = $qs->start($pid, $key);
            return !array_filter($r['notes'] ?? [],
                fn ($n) => str_contains($n, 'another module'));
        } catch (InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'required level');
        }
    };
    ok('a Rivermark party is not blocked from kessa_debt',
       $reachedLevelGate($made['rivermark'], 'kessa_debt'));
    ok("its own module's work is unaffected",
       $reachedLevelGate($made['old_city'], 'oldcity_the_missing_children'));
} finally {
    foreach ($made as $p) {
        foreach (['party_quest_stages','party_quests','world_flags'] as $t) {
            $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$p]);
        }
        $db->prepare('DELETE FROM parties WHERE id=?')->execute([$p]);
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail?1:0);
