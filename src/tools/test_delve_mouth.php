<?php
/**
 * Where a delve puts you when you climb out — and where it must never put you.
 *
 *   docker compose exec -T php php /var/www/html/tools/test_delve_mouth.php
 *
 * THE BUG THIS PINS. `dungeon_delves.mouth_location_id` is a foreign key
 * declared ON DELETE SET NULL, so any content load erases where every party
 * currently underground went in — and apply_content_safely.py, which exists to
 * carry player rows across exactly that operation, did not know the table
 * existed. `DelveEngine::mouthOf()` then fell back to a literal `uv_mouth`, on
 * a comment that said "every such delve is in the Undervault because that is
 * the only place one could happen". True when it was written; false the day
 * `has_delve` freed the stair from the module.
 *
 * So a party that walked down the Proving Yard's stair climbed out in the
 * Undervault — a module they never chose, reached without walking. It was
 * reported as "how did I get here, I just exited the delve".
 *
 * WHY test_delve.php DID NOT CATCH IT. That file descends from `uv_mouth`, so
 * the constant it fell back to happened to be the right answer every time. A
 * fallback is only ever wrong somewhere else, which is why this test delves
 * from the OTHER stair and nowhere near the Undervault.
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
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

$db = db();
$delves = new DelveEngine($db);

// The stair is in the CELLAR, not the common room: the Proving House keeps the
// inn upstairs and both ways down — the pit and the delve — under it. This test
// delved from the yard until that migration landed and then failed loudly,
// which is the right way round for a fixture that hard-codes where a stair is.
$yardId = (int) $db->query("SELECT id FROM locations WHERE location_key='_freeplay_cellar'")->fetchColumn();
$uvMouth = (int) $db->query("SELECT id FROM locations WHERE location_key='uv_mouth'")->fetchColumn();
ok('the free-play cellar has a stair', $yardId > 0);
ok('and so does the Undervault, which is the one we must not land in', $uvMouth > 0);
if ($yardId <= 0) {
    echo "\nRun the free-play, delve-anywhere and freeplay-inn migrations first.\n";
    exit(1);
}

// A free-play party: module_id NULL, exactly as CharacterGenerator leaves them.
$db->prepare('INSERT INTO parties (user_id, module_id, name) VALUES (NULL, NULL, ?)')
    ->execute(['__mouth_probe']);
$partyId = (int) $db->lastInsertId();

$charIds = [];
foreach ([['Yarrow', 'Fighter'], ['Bek', 'Rogue']] as [$name, $class]) {
    $db->prepare(
        'INSERT INTO characters (name, race, class, level, strength, dexterity, constitution,
            intelligence, wisdom, charisma, max_hp, current_hp, armor_class, speed,
            proficiency_bonus, current_location_id)
         VALUES (?, "Human", ?, 3, 12,12,12,12,12,12, 24,24, 14, 30, 2, ?)'
    )->execute(["__mouth_{$name}", $class, $yardId]);
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
        // fall through
    }
    $db->prepare('DELETE FROM dungeon_delves WHERE party_id = ?')->execute([$partyId]);
    $db->prepare('DELETE FROM world_flags WHERE party_id = ?')->execute([$partyId]);
    $db->prepare('DELETE FROM character_party WHERE party_id = ?')->execute([$partyId]);
    foreach ($charIds as $id) {
        $db->prepare('DELETE FROM characters WHERE id = ?')->execute([$id]);
    }
    $db->prepare('DELETE FROM parties WHERE id = ?')->execute([$partyId]);
};
register_shutdown_function($cleanup);

$whereIs = function (int $id) use ($db): ?string {
    $stmt = $db->prepare(
        'SELECT l.location_key FROM characters c
           LEFT JOIN locations l ON l.id = c.current_location_id WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    $k = $stmt->fetchColumn();
    return is_string($k) ? $k : null;
};

echo "\n== the mouth is recorded by key ==\n";
$delves->descend($leader, $partyId);
$row = $delves->current($partyId);
same('the delve records the stair it began at', $yardId, (int) $row['mouth_location_id']);
same('and records its key beside it', '_freeplay_cellar', (string) $row['mouth_location_key']);

echo "\n== climbing out of the yard's own hole ==\n";
$delves->end($partyId);
$home = array_map($whereIs, $charIds);
same('the party surfaces where they went in', ['_freeplay_cellar', '_freeplay_cellar'], $home);

echo "\n== a content load nulls the id: the key still brings them home ==\n";
$delves->descend($leader, $partyId);
// Exactly what `ON DELETE SET NULL` does when locations are rebuilt underneath
// a party who is already underground.
$db->prepare('UPDATE dungeon_delves SET mouth_location_id = NULL WHERE party_id = ?')
    ->execute([$partyId]);
$delves->end($partyId);
same('the key answers when the id has been erased',
    ['_freeplay_cellar', '_freeplay_cellar'], array_map($whereIs, $charIds));

echo "\n== both erased: the party's OWN game answers, never a constant ==\n";
$delves->descend($leader, $partyId);
$db->prepare(
    'UPDATE dungeon_delves SET mouth_location_id = NULL, mouth_location_key = NULL WHERE party_id = ?'
)->execute([$partyId]);
$delves->end($partyId);
$landed = array_map($whereIs, $charIds);
ok('a delve with no memory of its stair still surfaces somewhere',
    $landed[0] !== null);
// THE ASSERTION THE WHOLE FILE IS FOR.
ok('and it is NOT the Undervault',
    !in_array('uv_mouth', $landed, true) && !in_array('uv_camp', $landed, true),
    'landed at ' . implode(', ', array_map(fn ($k) => (string) $k, $landed)));
$stmt = $db->prepare(
    'SELECT m.module_key FROM characters c
       JOIN locations l ON l.id = c.current_location_id
       JOIN regions r ON r.id = l.region_id
       JOIN modules m ON m.id = r.module_id WHERE c.id = ?'
);
$stmt->execute([$leader]);
same('it is the module the party actually plays in', '_freeplay', (string) $stmt->fetchColumn());

echo "\n== and the floors were written into that module too ==\n";
$delves->descend($leader, $partyId);
$stmt = $db->prepare(
    'SELECT m.module_key FROM regions r JOIN modules m ON m.id = r.module_id WHERE r.id = ?'
);
$stmt->execute([(int) $delves->current($partyId)['region_id']]);
same('a generated floor belongs to the party\'s own game', '_freeplay', (string) $stmt->fetchColumn());
$delves->end($partyId);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
