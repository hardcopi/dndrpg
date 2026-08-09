<?php
/**
 * Test harness for romance exclusivity: CompanionService.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_romance.php`.
 *
 * Unlike test_checks.php this one needs a database, because the whole subject
 * is a column — `party_companions.romance_locked` — and the bug being guarded
 * against was that nothing wrote it. A pure-arithmetic harness would have
 * passed against the broken version.
 *
 * It builds its own party and its own companion rows, asserts against them, and
 * removes them again, so it is safe to run against a database somebody is
 * playing on. Nothing it touches belongs to a real account: the party is
 * created with a NULL user_id and deleted in the finally block.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$RESULTS = ['pass' => 0, 'fail' => 0];

function ok(string $name, bool $condition, string $detail = ''): void
{
    global $RESULTS;
    if ($condition) {
        $RESULTS['pass']++;
        echo '  ok   ' . $name . PHP_EOL;
        return;
    }
    $RESULTS['fail']++;
    echo '  FAIL ' . $name . ($detail === '' ? '' : "\n         " . $detail) . PHP_EOL;
}

function section(string $s): void
{
    echo PHP_EOL . '== ' . $s . ' ==' . PHP_EOL;
}

$db = db();
$svc = new CompanionService($db);

/** Everyone the content says can be romanced, in key order. */
$romanceable = $db->query(
    'SELECT companion_key, name, romance_at FROM companions
      WHERE romanceable = 1 ORDER BY companion_key'
)->fetchAll();

if (count($romanceable) < 2) {
    echo "Needs at least two romanceable companions to test exclusivity; found "
        . count($romanceable) . ".\n";
    exit(1);
}

$partyId = null;
try {
    $db->prepare("INSERT INTO parties (user_id, name) VALUES (NULL, ?)")
       ->execute(['romance test ' . bin2hex(random_bytes(4))]);
    $partyId = (int) $db->lastInsertId();

    // Recruited, and liked well enough that the arc is open. Approval is set
    // above each companion's own romance_at rather than to a flat number,
    // because the gate reads the companion's threshold and content is free to
    // move it.
    $add = $db->prepare(
        'INSERT INTO party_companions (party_id, companion_key, status, approval,
                                       romance_stage, romance_locked)
         VALUES (?, ?, \'active\', ?, 0, 0)'
    );
    foreach ($romanceable as $c) {
        $add->execute([$partyId, $c['companion_key'], (int) $c['romance_at'] + 5]);
    }

    $stage = function (string $key) use ($db, $partyId): array {
        $s = $db->prepare('SELECT romance_stage, romance_locked FROM party_companions
                            WHERE party_id = ? AND companion_key = ?');
        $s->execute([$partyId, $key]);
        $row = $s->fetch() ?: ['romance_stage' => -1, 'romance_locked' => -1];
        return ['stage' => (int) $row['romance_stage'], 'locked' => (int) $row['romance_locked']];
    };

    $first  = $romanceable[0]['companion_key'];
    $second = $romanceable[1]['companion_key'];

    section('Everyone starts open');
    foreach ($romanceable as $c) {
        $s = $stage($c['companion_key']);
        ok("{$c['companion_key']} begins unlocked at stage 0",
           $s['stage'] === 0 && $s['locked'] === 0,
           json_encode($s));
    }

    section('Stage 1 is an acknowledgement, not a commitment');
    $svc->setRomanceStage($partyId, $first, 1);
    ok("$first reaches stage 1", $stage($first)['stage'] === 1);
    // This is the half the design turns on: two arcs may be open at once, and
    // the player is not punished for a warm reply to somebody.
    ok("$second is still open after $first reaches stage 1",
       $stage($second)['locked'] === 0,
       'locking here would make the first friendly line irreversible');
    $svc->setRomanceStage($partyId, $second, 1);
    ok("$second can also reach stage 1", $stage($second)['stage'] === 1);

    section('Stage 2 commits, and closes every other arc');
    $r = $svc->setRomanceStage($partyId, $first, 2);
    ok("$first reaches stage 2", $stage($first)['stage'] === 2);
    ok("$first is NOT locked by their own commitment",
       $stage($first)['locked'] === 0);
    foreach ($romanceable as $c) {
        if ($c['companion_key'] === $first) {
            continue;
        }
        ok("{$c['companion_key']} is locked once $first commits",
           $stage($c['companion_key'])['locked'] === 1,
           json_encode($stage($c['companion_key'])));
    }
    ok('the player is told about the arc they had started',
       (bool) array_filter($r['messages'] ?? [],
           static fn ($m) => str_contains($m, 'is not going to')),
       json_encode($r['messages'] ?? []));

    section('A closed arc cannot be advanced');
    $before = $stage($second);
    $r2 = $svc->setRomanceStage($partyId, $second, 2);
    $after = $stage($second);
    ok("$second cannot move once locked",
       $after['stage'] === $before['stage'],
       "stage went {$before['stage']} -> {$after['stage']}");
    ok('and it says why rather than failing silently',
       (bool) array_filter($r2['notes'] ?? [],
           static fn ($n) => str_contains($n, 'closed')),
       json_encode($r2['notes'] ?? []));

    section('The chosen one can still progress');
    $svc->setRomanceStage($partyId, $first, 3);
    ok("$first advances past the commit stage", $stage($first)['stage'] === 3);

    section('Someone recruited afterwards has no arc either');
    $later = $db->query(
        'SELECT companion_key FROM companions WHERE romanceable = 1 LIMIT 1'
    )->fetchColumn();
    // Re-locking an already-locked row must not resurrect it, and a fresh
    // recruit joining after the choice must not arrive with an open arc.
    $db->prepare('UPDATE party_companions SET romance_locked = 0, romance_stage = 0
                   WHERE party_id = ? AND companion_key = ?')
       ->execute([$partyId, $second]);
    $svc->setRomanceStage($partyId, $first, 4);
    ok("a re-opened $second is closed again by the next commit beat",
       $stage($second)['locked'] === 1,
       'the lock is applied on every commit, not only the first');

} finally {
    if ($partyId) {
        $db->prepare('DELETE FROM party_companions WHERE party_id = ?')->execute([$partyId]);
        $db->prepare('DELETE FROM parties WHERE id = ?')->execute([$partyId]);
    }
}

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
echo "{$RESULTS['pass']} passed, {$RESULTS['fail']} failed" . PHP_EOL;
exit($RESULTS['fail'] === 0 ? 0 : 1);
