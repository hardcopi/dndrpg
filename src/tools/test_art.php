<?php
/**
 * One character, one face — wherever the question is asked from.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_art.php`.
 * Needs the database: the rule being tested is the shape of a JOIN, and there
 * is nothing to test about a JOIN without one.
 *
 * Why this exists
 * ---------------
 * "Which art does this character wear" is a three-way COALESCE — authored
 * companion template, then the player's own pick, then the class default — and
 * it has now been written down twice and got out of step twice. The first time,
 * recruitment copied the key onto the character row and the copy went stale.
 * The second, WorldState kept a shortened version with the companions leg
 * missing; since recruitment had stopped making that copy, `c.sprite_key` was
 * NULL for every companion and the query fell through to the class default.
 * Brother Aldric appeared as a generic cleric — a woman in gold armour — on
 * every screen fed from WorldState::context(), the dice roller among them.
 *
 * So the invariant is not "the COALESCE is correct" but "everybody agrees":
 * any two paths that resolve a character's art must return the same answer.
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
        return;
    }
    $RESULTS['fail']++;
    echo 'FAIL  ' . $name . ($detail === '' ? '' : '  (' . $detail . ')') . PHP_EOL;
}

function section(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

$db = db();
$gen = new CharacterGenerator($db);
$world = new WorldState($db);

// ---------------------------------------------------------------------------
section('Every path resolves the same face');

$parties = $db->query('SELECT DISTINCT party_id FROM character_party ORDER BY party_id')
    ->fetchAll(PDO::FETCH_COLUMN);

$checked = 0;
foreach ($parties as $partyId) {
    $ctx = $world->context((int) $partyId);
    foreach ($ctx['party'] as $member) {
        $id = (int) $member['id'];
        $canonical = $gen->getCharacter($id)['sprite_key'] ?? null;
        $fromWorld = $member['sprite_key'] ?? null;
        ok(
            sprintf('party %d: %s agrees between WorldState and CharacterGenerator', $partyId, $member['name']),
            $canonical === $fromWorld,
            'CharacterGenerator says ' . json_encode($canonical) . ', WorldState says ' . json_encode($fromWorld)
        );
        $checked++;
    }
}
ok('there were characters to check at all', $checked > 0, "checked {$checked}");

// ---------------------------------------------------------------------------
section('A companion wears their authored template, not their class default');

// The specific failure: a recruited companion whose `characters.sprite_key` is
// NULL must still get the face named in content/companions/*.json, never the
// generic one belonging to their class.
$companions = $db->query(
    'SELECT c.id, c.name, c.class, c.companion_key,
            c.sprite_key   AS own_pick,
            cmp.sprite_key AS template,
            cl.sprite_key  AS class_default
       FROM characters c
       INNER JOIN companions cmp ON cmp.companion_key = c.companion_key
       LEFT JOIN classes cl ON cl.name = c.class
      WHERE c.companion_key IS NOT NULL'
)->fetchAll();

foreach ($companions as $row) {
    $resolved = $gen->getCharacter((int) $row['id'])['sprite_key'] ?? null;
    ok(
        sprintf('%s wears %s', $row['name'], json_encode($row['template'])),
        $resolved === $row['template'],
        'resolved to ' . json_encode($resolved)
    );

    // Only meaningful where the two actually differ; where a companion's
    // template happens to equal their class default there is nothing to catch.
    if ($row['own_pick'] === null && $row['template'] !== $row['class_default']) {
        ok(
            sprintf('%s is not fobbed off with the class default %s', $row['name'], json_encode($row['class_default'])),
            $resolved !== $row['class_default']
        );
    }
}
ok('there were companions to check', count($companions) > 0, count($companions) . ' found');

// ---------------------------------------------------------------------------
section('The art a character resolves to is actually on disk');

// A missing PNG does not 404 here — the vhost falls through to index.php and
// serves the homepage with a 200 — so a portrait that is not there looks
// exactly like one that is, from the status code. Check the file.
$dir = dirname(__DIR__) . '/assets/images/npcs/';
$seen = [];
foreach ($parties as $partyId) {
    foreach ($world->context((int) $partyId)['party'] as $member) {
        $key = (string) ($member['sprite_key'] ?? '');
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        ok("{$key}_bust.png exists", is_file($dir . $key . '_bust.png'));
    }
}

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
