<?php
/**
 * Hesta asks "Are they done?" once, not again after you have answered.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_hesta_report.php`.
 * Needs the database: the subject is party-scoped flags and quest stages.
 *
 * The bug this guards: her "I heard it through the floor" greeting keyed on
 * the rats being beaten and nothing else. Telling her the grate had been
 * moved (The Carters' Cellar → the_drain) left that flag where it was, so the
 * next conversation asked "Are they done?" all over again — and the second
 * answer, "It was rats", could then close the quest as cleared after she had
 * already been told about the grate. At the_drain she now falls through to
 * her authored "Still down there?" line.
 *
 * Builds its own party and removes it. Safe against a database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
    (SELECT id FROM modules WHERE module_key='waerhaven'), 'hesta probe')")->execute();
$pid = (int) $db->lastInsertId();
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass,$fail;
    if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n"; } }

function hesta(int $pid): string {
    $n = (new DialogEngine(db()))->node($pid, 'hesta_orrow', 'greeting');
    return trim(preg_replace('/\s+/', ' ', $n['text']));
}
const ASKS = 'I heard it through the floor';
try {
    $world = new WorldState($db);
    $world->set($pid, 'met_hesta');
    $world->set($pid, 'cellar_rats_beaten');

    // Down there without the job: she still heard it.
    ok('rats beaten, no job: she asks', str_contains(hesta($pid), ASKS), mb_substr(hesta($pid), 0, 90));

    $quests = new QuestService($db);
    $quests->start($pid, 'the_carters_cellar');
    ok('rats beaten, job at go_down: she asks', str_contains(hesta($pid), ASKS), mb_substr(hesta($pid), 0, 90));

    // Told about the grate: the_grate_told moves the job to the_drain.
    $quests->advance($pid, 'the_carters_cellar', 'the_drain');
    $after = hesta($pid);
    ok('told about the grate: she does not ask again', !str_contains($after, ASKS), mb_substr($after, 0, 90));
    ok('and says the trap is where it was', str_contains($after, 'Still down there?'), mb_substr($after, 0, 90));

    $world->set($pid, 'cellar_cleared');
    ok('cleared: the cellar is hers again', str_contains(hesta($pid), "Cellar's mine again"), mb_substr(hesta($pid), 0, 90));
} finally {
    foreach (['party_quest_stages','party_quests','world_flags'] as $t) {
        $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$pid]);
    }
    $db->prepare('DELETE FROM parties WHERE id=?')->execute([$pid]);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
