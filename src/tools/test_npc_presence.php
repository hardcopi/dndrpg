<?php
/**
 * Who is in the room, and what the player says to somebody they invite into it.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_npc_presence.php`.
 *
 * Two playtest faults from the same scene, the Golden Flagon's common room:
 *
 *  - Garrow Finch, the debt factor who comes for Kessa, was drinking at the
 *    next table from the first night, while Kessa told the party somebody was
 *    coming for her. His `place` now carries conditions
 *    (npcs.place_conditions_json) and LocationEngine::npcsAt() leaves him out
 *    until Kessa's debt has caught up with her: Kessa travelling with the
 *    party at `kessa_debt`'s level. The other half of the rule is the part
 *    that would rot quietly — a save that has met him, or has his quest in any
 *    state, must keep seeing him, or a debt half-paid has nobody to pay.
 *
 *  - The invitation said "Come with us." to a player on their own. It is two
 *    replies now, on `party_size_at_least`, and exactly one shows.
 *
 * Builds its own parties and removes them. Safe against a database in use.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$gen = new CharacterGenerator($db);
$loc = new LocationEngine($db);
$dlg = new DialogEngine($db);
$comp = new CompanionService($db);
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

$parties = [];
$chars = [];
$make = function () use ($gen, $db, &$parties, &$chars): array {
    $standard = ['strength' => 15, 'dexterity' => 14, 'constitution' => 13,
                 'intelligence' => 12, 'wisdom' => 10, 'charisma' => 8];
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
        (SELECT id FROM modules WHERE module_key = 'rivermark'), ?)")
       ->execute(['presence probe ' . bin2hex(random_bytes(3))]);
    $pid = (int) $db->lastInsertId();
    $parties[] = $pid;
    $c = $gen->create([
        'name' => 'Presence ' . bin2hex(random_bytes(4)), 'race' => 'Human', 'class' => 'Fighter',
        'method' => 'standard', 'abilities' => $standard, 'background' => 'Soldier',
        'alignment' => 'Neutral', 'sprite_key' => 'fighter',
    ], $pid);
    $cid = (int) $c['id'];
    $chars[] = $cid;
    $db->prepare("UPDATE characters SET current_location_id =
        (SELECT id FROM locations WHERE location_key = 'flagon_common_room'), gold = 200 WHERE id = ?")
       ->execute([$cid]);
    return [$pid, $cid];
};
$here = function (int $cid, int $pid) use ($loc, $db): array {
    $at = (int) $db->query("SELECT id FROM locations WHERE location_key = 'flagon_common_room'")->fetchColumn();
    $state = $loc->getState($cid, $pid)['location'] ?? [];
    ok('the probe is standing in the common room', (int) ($state['id'] ?? 0) === $at,
        'at ' . var_export($state['key'] ?? null, true));
    return array_column($state['npcs'] ?? [], 'npc_key');
};
// A fresh engine per question: WorldState caches a party's flags for the life
// of the instance, and a flag this test sets would not be seen by one made
// before it.
$labels = function (int $pid, string $npc, string $node) use ($db): array {
    return array_column((new DialogEngine($db))->node($pid, $npc, $node)['choices'] ?? [], 'label');
};
$setLevel = fn (int $cid, int $lv) => $db->prepare('UPDATE characters SET level = ? WHERE id = ?')->execute([$lv, $cid]);

try {
    section('The column is there and the content wrote it');
    $raw = $db->query("SELECT place_conditions_json FROM npcs WHERE npc_key = 'garrow_finch'")->fetchColumn();
    ok('garrow_finch has place conditions', is_string($raw) && is_array(json_decode($raw, true)), var_export($raw, true));
    ok('Mara has none, and is simply there',
        $db->query("SELECT place_conditions_json FROM npcs WHERE npc_key = 'mara_hearthstone'")->fetchColumn() === null);

    section('A new party, alone, on the first night');
    [$pid, $cid] = $make();
    $who = $here($cid, $pid);
    ok('Kessa is at the bar', in_array('kessa', $who, true), implode(', ', $who));
    ok('Finch is not in the room yet', !in_array('garrow_finch', $who, true), implode(', ', $who));
    $l = $labels($pid, 'kessa', 'the_pitch');
    ok('alone, the invitation is "Come with me."', in_array('Come with me.', $l, true) && !in_array('Come with us.', $l, true), implode(' | ', $l));
    $l = $labels($pid, 'kessa', 'she_admits_the_debt');
    ok('and "Then move with me."', in_array('Then move with me.', $l, true) && !in_array('Then move with us.', $l, true), implode(' | ', $l));

    section('Kessa joins, at level 1');
    $comp->recruit($pid, 'kessa');
    $who = $here($cid, $pid);
    ok('Finch is still not here: the debt is a level-2 quest', !in_array('garrow_finch', $who, true), implode(', ', $who));
    (new WorldState($db))->set($pid, 'met_aldric');   // the invitation is on his second greeting
    $l = $labels($pid, 'aldric', 'greeting');
    ok('with Kessa walking, Aldric is asked "Come with us."', in_array('Come with us.', $l, true) && !in_array('Come with me.', $l, true), implode(' | ', $l));

    section('Level 2: the debt has caught up');
    $setLevel($cid, 2);
    $who = $here($cid, $pid);
    ok('Finch is in the common room', in_array('garrow_finch', $who, true), implode(', ', $who));

    section('Talking to him still works end to end');
    $n = $dlg->node($pid, 'garrow_finch', null);
    $quest = (new WorldState($db))->context($pid, $cid)['quests']['kessa_debt'] ?? null;
    ok('his greeting starts kessa_debt', ($quest['status'] ?? null) === 'active', json_encode($quest));
    $idx = array_search('Whose debt?', array_column($n['choices'], 'label'), true);
    $dlg->choose($pid, 'garrow_finch', (string) $n['node_key'], (int) $idx);
    $p = $dlg->node($pid, 'garrow_finch', 'the_paper');
    $idx = array_search('Ninety, then. Take it and go.', array_column($p['choices'], 'label'), true);
    ok('the paper offers to pay', $idx !== false, implode(' | ', array_column($p['choices'], 'label')));
    $dlg->choose($pid, 'garrow_finch', 'the_paper', (int) $idx);
    $quest = (new WorldState($db))->context($pid, $cid)['quests']['kessa_debt'] ?? null;
    ok('paying completes kessa_debt', ($quest['status'] ?? null) === 'completed', json_encode($quest));

    section('A save past the moment keeps him');
    $setLevel($cid, 1);
    $db->prepare("UPDATE party_companions SET status = 'left' WHERE party_id = ? AND companion_key = 'kessa'")->execute([$pid]);
    $who = $here($cid, $pid);
    ok('quest finished, Kessa gone, level 1: Finch is still there to be spoken to', in_array('garrow_finch', $who, true), implode(', ', $who));

    [$pid2, $cid2] = $make();
    (new WorldState($db))->set($pid2, 'met_garrow');
    $who = $here($cid2, $pid2);
    ok('a save that met him before this change still sees him', in_array('garrow_finch', $who, true), implode(', ', $who));
} finally {
    // Recruiting makes a characters row for the companion; it belongs to the
    // probe party and goes with it.
    foreach ($parties as $p) {
        $ids = $db->query('SELECT character_id FROM party_companions WHERE character_id IS NOT NULL AND party_id = ' . (int) $p)
            ->fetchAll(PDO::FETCH_COLUMN);
        $chars = array_merge($chars, array_map('intval', $ids));
    }
    foreach ($chars as $c) {
        $db->prepare('DELETE FROM characters WHERE id = ?')->execute([$c]);
    }
    foreach ($parties as $p) {
        $db->prepare('DELETE FROM parties WHERE id = ?')->execute([$p]);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
