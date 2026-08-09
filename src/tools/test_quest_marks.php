<?php
/**
 * The "has work" mark and the quest-stage arrival note — the two halves of
 * "quest markers with no way of getting the quest" and "quests feel like
 * dead ends".
 *
 * The mark's rule: an NPC wears "!" only when their OWN dialogue can start a
 * quest this party can still take. Named-giver-but-board-only and
 * flagged-but-linked-to-nothing both stay dark — those were the phantom marks.
 *
 * NEEDS the database; scratch rows inside a transaction it rolls back. Safe
 * against a database somebody is playing on.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$engine = new LocationEngine($db);
$invoke = function (string $name, ...$args) use ($engine) {
    $m = new ReflectionMethod(LocationEngine::class, $name);
    $m->setAccessible(true);
    return $m->invoke($engine, ...$args);
};

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        echo "  ok   $n\n";
    } else {
        $fail++;
        echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n";
    }
}

$db->beginTransaction();
try {
    // A stage to stand on: any real location and any real module.
    $locationId = (int) $db->query('SELECT id FROM locations ORDER BY id LIMIT 1')->fetchColumn();
    $moduleId = (int) $db->query('SELECT id FROM modules ORDER BY id LIMIT 1')->fetchColumn();

    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, 'mark probe')")
        ->execute([$moduleId]);
    $partyId = (int) $db->lastInsertId();

    $db->prepare(
        "INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
            constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
            proficiency_bonus, is_active)
         VALUES (?, 'Human', 'Fighter', 3, 0, 10, 10, 10, 10, 10, 10, 10, 10, 10, 2, 1)"
    )->execute(['_test_' . bin2hex(random_bytes(4))]);
    $leaderId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,1)')
        ->execute([$partyId, $leaderId]);

    $mkQuest = function (string $key, int $level = 1) use ($db): int {
        $db->prepare(
            'INSERT INTO quests (quest_key, title, required_level, is_active) VALUES (?,?,?,1)'
        )->execute([$key, "Probe {$key}", $level]);
        return (int) $db->lastInsertId();
    };
    $mkNpc = function (string $name, ?string $dialogue, ?int $giverOf = null) use ($db, $locationId): array {
        $db->prepare(
            'INSERT INTO npcs (npc_key, name, is_quest_giver, location_id, dialogue_json)
             VALUES (?,?,1,?,?)'
        )->execute(['_probe_' . bin2hex(random_bytes(4)), $name, $locationId, $dialogue]);
        $id = (int) $db->lastInsertId();
        if ($giverOf !== null) {
            $db->prepare('UPDATE quests SET giver_npc_id = ? WHERE id = ?')->execute([$id, $giverOf]);
        }
        return ['id' => $id, 'name' => $name];
    };
    $starts = fn (string $key) => json_encode([
        'start' => 'greeting',
        'nodes' => ['greeting' => [['text' => 'Work?', 'choices' => [
            ['label' => 'Yes.', 'effects' => [['start_quest' => $key]]],
        ]]]],
    ]);

    echo "The mark\n";

    $qa = $mkQuest('_probe_open_' . bin2hex(random_bytes(3)));
    $qaKey = $db->query("SELECT quest_key FROM quests WHERE id = {$qa}")->fetchColumn();
    $offering = $mkNpc('Offering', $starts((string) $qaKey));
    ok('their own dialogue starts an untaken quest — mark on',
        $invoke('hasWorkFor', $offering, $partyId) === true);

    $db->prepare("INSERT INTO party_quests (party_id, quest_id, status) VALUES (?,?, 'active')")
        ->execute([$partyId, $qa]);
    ok('the same quest taken — mark off',
        $invoke('hasWorkFor', $offering, $partyId) === false);

    $phantom = $mkNpc('Phantom', null);
    ok('flagged as a giver with no dialogue at all — mark off (the phantom fix)',
        $invoke('hasWorkFor', $phantom, $partyId) === false);

    $qb = $mkQuest('_probe_board_' . bin2hex(random_bytes(3)));
    $boardOnly = $mkNpc('Board-only', json_encode(['start' => 'g', 'nodes' => ['g' => [['text' => 'Hm.']]]]), $qb);
    ok('named giver whose own mouth starts nothing — mark off (Osric\'s case)',
        $invoke('hasWorkFor', $boardOnly, $partyId) === false);

    $qc = $mkQuest('_probe_high_' . bin2hex(random_bytes(3)), 6);
    $qcKey = $db->query("SELECT quest_key FROM quests WHERE id = {$qc}")->fetchColumn();
    $tooSoon = $mkNpc('Too-soon', $starts((string) $qcKey));
    ok('a job the party is too green for — mark off',
        $invoke('hasWorkFor', $tooSoon, $partyId) === false);

    // Rilk's shape: the offer exists in the tree, but only behind a greeting
    // variant whose conditions this party does not meet yet. The mark must
    // wait for the conditions, exactly as the conversation will.
    $world = new WorldState($db);
    $ctxOf = fn () => $world->context($partyId, $leaderId);
    $qe = $mkQuest('_probe_gated_' . bin2hex(random_bytes(3)));
    $qeKey = (string) $db->query("SELECT quest_key FROM quests WHERE id = {$qe}")->fetchColumn();
    $gated = $mkNpc('Rilk-shaped', json_encode([
        'start' => 'greeting',
        'nodes' => [
            'greeting' => [
                ['conditions' => [['flag' => '_probe_trusted']],
                 'text' => 'You will do.',
                 'choices' => [['label' => 'Take me down.', 'effects' => [['start_quest' => $qeKey]]]]],
                ['text' => 'Wasn\'t me.',
                 'choices' => [['label' => 'Leave him.', 'end' => true]]],
            ],
        ],
    ]));
    ok('an offer behind an unmet condition — mark off (Rilk\'s case)',
        $invoke('hasWorkFor', $gated, $partyId, $ctxOf()) === false);
    $world->set($partyId, '_probe_trusted');
    ok('the condition met, the same offer — mark on',
        $invoke('hasWorkFor', $gated, $partyId, $ctxOf()) === true);

    // And the same gate on the choice rather than the variant.
    $qf = $mkQuest('_probe_choice_' . bin2hex(random_bytes(3)));
    $qfKey = (string) $db->query("SELECT quest_key FROM quests WHERE id = {$qf}")->fetchColumn();
    $choiceGated = $mkNpc('Choice-gated', json_encode([
        'start' => 'greeting',
        'nodes' => [
            'greeting' => [[
                'text' => 'Hm.',
                'choices' => [
                    ['label' => 'About that job.', 'conditions' => [['flag' => '_probe_vouched']],
                     'effects' => [['start_quest' => $qfKey]]],
                    ['label' => 'Goodbye.', 'end' => true],
                ],
            ]],
        ],
    ]));
    ok('a gated choice hides its offer too',
        $invoke('hasWorkFor', $choiceGated, $partyId, $ctxOf()) === false);
    $world->set($partyId, '_probe_vouched');
    ok('and shows it when it opens',
        $invoke('hasWorkFor', $choiceGated, $partyId, $ctxOf()) === true);

    echo "\nThe arrival note\n";

    $qd = $mkQuest('_probe_walk_' . bin2hex(random_bytes(3)));
    $db->prepare(
        'INSERT INTO quest_stages (quest_id, stage_key, title, objective, target_location_id, is_terminal)
         VALUES (?, \'go_look\', \'Go and look\', \'Look at the place\', ?, 0)'
    )->execute([$qd, $locationId]);
    $stageId = (int) $db->lastInsertId();
    $db->prepare(
        "INSERT INTO party_quests (party_id, quest_id, status, current_stage_id)
         VALUES (?,?, 'active', ?)"
    )->execute([$partyId, $qd, $stageId]);

    $notes = $invoke('questArrivalNotes', $partyId, $locationId);
    ok('arriving where the stage points says so', count($notes) === 1,
        var_export($notes, true));
    $qdKey = $db->query("SELECT quest_key FROM quests WHERE id = {$qd}")->fetchColumn();
    $flag = (new WorldState($db))->number($partyId, "seen:{$qdKey}:go_look");
    ok('and records that the party stood there', $flag > 0);
    ok('but only says it once', $invoke('questArrivalNotes', $partyId, $locationId) === []);

    $elsewhere = (int) $db->query(
        "SELECT id FROM locations WHERE id <> {$locationId} ORDER BY id LIMIT 1"
    )->fetchColumn();
    ok('and says nothing anywhere else',
        $invoke('questArrivalNotes', $partyId, $elsewhere) === []);
} finally {
    $db->rollBack();
}

echo "\n" . ($fail === 0 ? "0 failed" : "{$fail} FAILED") . " ({$pass} passed)\n";
exit($fail === 0 ? 0 : 1);
