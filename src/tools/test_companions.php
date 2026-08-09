<?php
/**
 * Companion conversations, end to end: the Talk path (start-node fallback),
 * the camp hub's romance choices, topic retirement, interjection `once`, and
 * the romance-threshold signal. Driven through the real DialogEngine over the
 * real authored content, because the bug this guards against — every romance
 * arc unreachable in play while test_romance.php passed — lived exactly in
 * the gap between the service and the conversation.
 *
 * NEEDS the database. Scratch party inside a transaction it rolls back; safe
 * against a database somebody is playing on.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();

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
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

/** The filtered index of the choice whose label starts with $prefix, or -1. */
function choiceAt(array $node, string $prefix): int
{
    foreach ($node['choices'] as $i => $c) {
        if (str_starts_with((string) $c['label'], $prefix)) {
            return (int) $i;
        }
    }
    return -1;
}

$db->beginTransaction();
try {
    $moduleId = (int) $db->query('SELECT id FROM modules WHERE module_key = "rivermark"')->fetchColumn();
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, 'companion probe')")
        ->execute([$moduleId]);
    $pid = (int) $db->lastInsertId();
    $db->prepare(
        "INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
            constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
            proficiency_bonus, is_active)
         VALUES (?, 'Human', 'Fighter', 3, 0, 10,10,10,10,10,10, 20, 20, 12, 2, 1)"
    )->execute(['_test_' . bin2hex(random_bytes(4))]);
    $leader = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,1)')
        ->execute([$pid, $leader]);
    $db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')->execute([$leader, $pid]);

    $companions = new CompanionService($db);
    $engine = new DialogEngine($db);
    $world = new WorldState($db);
    $approve = fn (string $k, int $v) => $db->prepare(
        'UPDATE party_companions SET approval = ? WHERE party_id = ? AND companion_key = ?'
    )->execute([$v, $pid, $k]);
    $stateOf = function (string $k) use ($db, $pid): array {
        $s = $db->prepare('SELECT * FROM party_companions WHERE party_id = ? AND companion_key = ?');
        $s->execute([$pid, $k]);
        return $s->fetch() ?: [];
    };

    // All three recruited before anybody commits: exclusivity is tested below
    // by committing, and a companion recruited AFTER a commitment is locked on
    // the way in — which is its own assertion at the end.
    $companions->recruit($pid, 'kessa');
    $companions->recruit($pid, 'aldric');
    $companions->recruit($pid, 'ilse');

    echo "The threshold speaks\n";

    $approve('ilse', 25);
    $r = $companions->adjustApproval($pid, 'ilse', 10);
    same('crossing romance_at is reported', 'romance', $r['crossed']);
    ok('with a name to hang the line on', ($r['name'] ?? '') !== '');
    $approve('ilse', 0);

    echo "The Talk path\n";

    $node = $engine->node($pid, 'kessa', null);
    same('an empty node key opens the tree start', 'greeting', $node['node_key']);
    $node = $engine->node($pid, 'jimmy_able', null);
    same("and Jimmy's start is hail, not greeting", 'hail', $node['node_key']);

    echo "\nThe camp hub's romance door\n";

    $approve('kessa', 10);
    $camp = $engine->node($pid, 'kessa', 'camp');
    ok('below the threshold, no offer on the menu',
        choiceAt($camp, "You've been halfway") === -1);

    $approve('kessa', 35);
    $camp = $engine->node($pid, 'kessa', 'camp');
    $offer = choiceAt($camp, "You've been halfway");
    ok('past it, the offer appears', $offer >= 0);

    $bridge = $engine->choose($pid, 'kessa', 'camp', $offer)['node'] ?? null;
    ok('and leads to the confession scene', $bridge !== null && $bridge['node_key'] === 'kessa_close');
    $go = -1;
    foreach ($bridge['choices'] as $i => $c) {
        if (!empty($c['label']) && $c['label'] !== 'Get some sleep.' && $c['label'] !== 'Take your time.'
            && $c['label'] !== 'Nothing.' && $c['label'] !== 'Another night.') {
            $go = $i;
            break;
        }
    }
    $one = $engine->choose($pid, 'kessa', 'kessa_close', $go)['node'] ?? null;
    same('the scene reaches romance_one', 'romance_one', $one['node_key'] ?? null);
    same('and the first beat is recorded', 1, (int) $stateOf('kessa')['romance_stage']);

    $two = $engine->choose($pid, 'kessa', 'romance_one', 0)['node'] ?? null;
    same('the answer reaches romance_two', 'romance_two', $two['node_key'] ?? null);
    same('the commitment is recorded', 2, (int) $stateOf('kessa')['romance_stage']);
    same('and every other heart closes', 1, (int) $stateOf('aldric')['romance_locked']);

    $aldricCamp = $engine->node($pid, 'aldric', 'camp');
    $approve('aldric', 35);
    $aldricCamp = $engine->node($pid, 'aldric', 'camp');
    ok('a locked companion never shows the offer',
        choiceAt($aldricCamp, "You've held that strap") === -1);

    $camp = $engine->node($pid, 'kessa', 'camp');
    $sit = choiceAt($camp, 'Come sit by me.');
    ok('the settled beat is on the menu at stage 2', $sit >= 0);
    $after = $engine->choose($pid, 'kessa', 'camp', $sit)['node'] ?? null;
    same('and plays', 'romance_after', $after['node_key'] ?? null);

    echo "\nTopics retire\n";

    $approve('kessa', 45);
    $camp = $engine->node($pid, 'kessa', 'camp');
    $mark = choiceAt($camp, 'What did you put your mark on?');
    ok('an untold story is on the menu', $mark >= 0);
    $engine->choose($pid, 'kessa', 'camp', $mark);
    ok('telling it sets the told-flag', $world->number($pid, 'camp_kessa_the_mark') > 0);
    $camp = $engine->node($pid, 'kessa', 'camp');
    ok('and the menu lets it go', choiceAt($camp, 'What did you put your mark on?') === -1);

    echo "\nInterjections land once\n";

    $db->prepare(
        'INSERT INTO npcs (npc_key, name, dialogue_json) VALUES (?, ?, ?)'
    )->execute(['_probe_bystander', 'Bystander', json_encode([
        'start' => 'greeting',
        'nodes' => ['greeting' => [[
            'text' => 'A man nods at you.',
            'interjections' => [
                ['companion' => 'kessa', 'text' => 'Kessa: "I know him."', 'once' => true],
                ['companion' => 'kessa', 'text' => 'Kessa: "Still know him."'],
            ],
            'choices' => [['label' => 'Move on.', 'end' => true]],
        ]]],
    ])]);
    $first = $engine->node($pid, '_probe_bystander', null);
    same('both asides play the first time', 2, count($first['interjections']));
    $second = $engine->node($pid, '_probe_bystander', null);
    same('the once-aside stays said', 1, count($second['interjections']));
    same('and the ordinary one remains', 'Kessa: "Still know him."',
        $second['interjections'][0]['text'] ?? null);

    echo "\nExclusivity outlives the moment it was decided\n";

    // Ilse was locked when Kessa committed, so re-crossing must stay silent.
    $approve('ilse', 25);
    $r = $companions->adjustApproval($pid, 'ilse', 10);
    same('a locked heart crossing the threshold is not reported', null, $r['crossed']);

    // And somebody recruited AFTER the commitment is locked on the way in —
    // otherwise they would be the one door still open to a committed player.
    $companions->recruit($pid, 'sera');
    same('a companion recruited after the commitment arrives locked',
        1, (int) $stateOf('sera')['romance_locked']);
    $approve('sera', 45);
    $seraCamp = $engine->node($pid, 'sera', 'camp');
    ok('and their camp never offers the arc',
        choiceAt($seraCamp, "The tablet's been blank") === -1);
} finally {
    $db->rollBack();
}

echo "\n" . ($fail === 0 ? "0 failed" : "{$fail} FAILED") . " ({$pass} passed)\n";
exit($fail === 0 ? 0 : 1);
