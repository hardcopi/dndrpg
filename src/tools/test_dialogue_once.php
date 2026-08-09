<?php
/**
 * A `once` dialogue beat must survive being drawn without being answered.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_dialogue_once.php`.
 * Needs the database: the subject is a party-scoped flag.
 *
 * The bug this guards: `once` was spent when the variant was RESOLVED, not
 * when the player answered it. Opening a conversation and closing it — or
 * clicking the same person twice, since Dialog.open() closes and re-opens —
 * burned the beat without a word of it reaching the screen. Sella Carrow's
 * introduction went that way on the first conversation of a playthrough, and
 * as it was the only place she says who she or her daughter are, there was no
 * way back to it.
 *
 * Also asserts the engine's own bookkeeping does not travel to the browser:
 * `_raw` carried every reply's effects, conditions and DCs out to the page.
 *
 * Builds its own party and removes it. Safe against a database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
    (SELECT id FROM modules WHERE module_key='old_city'), 'once probe')")->execute();
$pid = (int) $db->lastInsertId();
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass,$fail;
    if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n"; } }

function opening(int $pid): string {
    $n = (new DialogEngine(db()))->node($pid, 'sella_carrow', 'hail');
    return trim(preg_replace('/\s+/', ' ', $n['text']));
}
try {
    $first  = opening($pid);
    ok('first open is the introduction', str_contains($first, 'There is a woman sitting'), $first);

    // Walk away without answering. Then click her again — the double-click case.
    $second = opening($pid);
    ok('opening again still shows it', str_contains($second, 'There is a woman sitting'), $second);
    $third  = opening($pid);
    ok('and again', str_contains($third, 'There is a woman sitting'), $third);

    // Now answer it.
    (new DialogEngine($db))->choose($pid, 'sella_carrow', 'hail', 2);   // "Not our business."
    $after = opening($pid);
    ok('once answered, it retires', !str_contains($after, 'There is a woman sitting'), $after);
    ok('and the return greeting names Nell', str_contains($after, 'Nell Carrow'), mb_substr($after, 0, 90));

    // Nothing internal reaches the client.
    $n = (new DialogEngine($db))->node($pid, 'sella_carrow', 'hail');
    $leaked = array_key_exists('_variant', $n)
        || (bool) array_filter($n['choices'], fn ($c) => isset($c['_raw']) || isset($c['_authored']));
    ok('no engine internals in the payload', !$leaked, json_encode(array_keys($n)));
} finally {
    foreach (['party_quest_stages','party_quests','world_flags'] as $t) {
        $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$pid]);
    }
    $db->prepare('DELETE FROM parties WHERE id=?')->execute([$pid]);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
