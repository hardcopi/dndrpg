<?php
/**
 * Act II side quest "The Fenmoor Lot" (the armour chain) plays to each ending.
 * Below is the harness shared with test_act_two.php; the original notes follow.
 *
 * Act II, "The Quarterly Meeting", can be played through on all four branches.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_act_two.php`.
 *
 * For each `survey_fate` (took, burned, sold, warned) it builds a level-5 party
 * at the packet stair with the Act I hand-off flags set, boards Tull's packet,
 * and plays the act to an ending by choosing replies by their words, the way a
 * player does. Then it checks the flags each ending writes and that the
 * meeting's chair speaks to the branch the party came from.
 *
 * The loader proves the files agree with each other; this proves the act is
 * walkable — no reply that should be offered is missing, no node dead-ends.
 * Builds its own parties and removes them. Safe against a database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass,$fail;
  if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n".($d?"\n         $d":'')."\n"; } }

$made = [];
function party(PDO $db, array $flags, int $gold = 500): int {
    global $made;
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
        (SELECT id FROM modules WHERE module_key='rivermark'), 'fenmoor probe')")->execute();
    $pid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
            constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
            proficiency_bonus, is_active, gold, current_location_id)
        VALUES (?, 'Human', 'Fighter', 5, 6500, 14, 12, 14, 12, 12, 12, 44, 44, 16, 3, 1, ?,
                (SELECT id FROM locations WHERE location_key='packet_stair'))")
        ->execute(['_fenmoor_probe_' . bin2hex(random_bytes(3)), $gold]);
    $cid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,1)')->execute([$pid, $cid]);
    $db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')->execute([$cid, $pid]);
    $made[] = [$pid, $cid];
    $fx = new Effects($db);
    foreach ($flags as $k => $v) { $fx->apply($pid, [['set_flag' => $k, 'value' => (string) $v]]); }
    return $pid;
}
/** Open `npc` and take replies by label substring; returns the last node seen. */
function talk(PDO $db, int $pid, string $npc, array $labels): array {
    $de = new DialogEngine($db);
    $node = $de->node($pid, $npc);
    $key = (string) ($node['node_key'] ?? $node['key'] ?? 'greeting');
    foreach ($labels as $want) {
        $idx = null;
        foreach ($node['choices'] ?? [] as $i => $c) {
            if (stripos((string) $c['label'], $want) !== false && empty($c['locked']) && empty($c['spent'])) { $idx = $i; break; }
        }
        if ($idx === null) {
            $have = implode(' | ', array_map(fn ($c) => $c['label'], $node['choices'] ?? []));
            throw new RuntimeException("$npc/$key: no reply like '$want' (have: $have)");
        }
        $de = new DialogEngine($db);
        $r = $de->choose($pid, $npc, $key, $idx);
        if (isset($r['node'])) { $node = $r['node']; $key = (string) ($node['node_key'] ?? $node['key'] ?? $key); }
        else { $node = ['choices' => [], 'closed' => true, 'r' => $r]; }
    }
    return $node;
}
function flag(PDO $db, int $pid, string $k): ?string { return (new WorldState($db))->get($pid, $k); }
function qstatus(PDO $db, int $pid, string $q): ?string { return (new QuestService($db, new Effects($db)))->statusFor($pid, $q); }
function here(PDO $db, int $pid): string {
    return (string) $db->query("SELECT l.location_key FROM character_party cp JOIN characters c ON c.id=cp.character_id JOIN locations l ON l.id=c.current_location_id WHERE cp.party_id=$pid LIMIT 1")->fetchColumn();
}
function opening(PDO $db, int $pid): void {
    talk($db, $pid, 'osgar_tull', ['Go aboard']);
    talk($db, $pid, 'abel_moss', ['looking for the house', 'Number Nine']);
}
$base = ['act_two_open' => 1, 'passage_booked' => 1, 'survey_fate' => 'took', 'mark_seen_plate' => 1];
function gold(PDO $db, int $p): int { return (int) $db->query("SELECT SUM(c.gold) FROM character_party cp JOIN characters c ON c.id=cp.character_id WHERE cp.party_id=$p")->fetchColumn(); }
function trail(PDO $db, int $p): void {
    talk($db, $p, 'dorcas_fenn', ['Fenmoor', 'tuppence']);
    ok('the Greyhythe return names Brindle', (new QuestService($db, new Effects($db)))->statusFor($p, 'the_fenmoor_lot') === 'active');
    talk($db, $p, 'hester_brindle', ['On spec', 'Where do I find', 'Thank you']);
    talk($db, $p, 'coll_darrow', ['price to the penny', 'Where is he', 'blue rail']);
    talk($db, $p, 'lise_fenner', ['four winters', 'Who asked']);
}
try {
    echo "== kept the book ==\n";
    $p = party($db, $base);
    trail($db, $p);
    talk($db, $p, 'lise_fenner', ['four winters', 'May I take']);
    ok('ends kept', flag($db, $p, 'armour_chain_fate') === 'kept' && qstatus($db, $p, 'the_fenmoor_lot') === 'completed');
    $has = (int) $db->query("SELECT COUNT(*) FROM character_inventory ci JOIN items i ON i.id=ci.item_id JOIN character_party cp ON cp.character_id=ci.character_id WHERE cp.party_id=$p AND i.item_key='wat_fenners_day_book'")->fetchColumn();
    ok('the day-book is carried', $has > 0);

    echo "== left the book ==\n";
    $p = party($db, $base);
    trail($db, $p);
    talk($db, $p, 'lise_fenner', ['four winters', "Keep it"]);
    ok('ends left', flag($db, $p, 'armour_chain_fate') === 'left' && flag($db, $p, 'armour_chain_ended') === '1');

    echo "== paid the burying ==\n";
    $p = party($db, $base, 100);
    trail($db, $p);
    talk($db, $p, 'lise_fenner', ['four winters', 'burying']);
    ok('ends paid', flag($db, $p, 'armour_chain_fate') === 'paid');
    ok('fourteen gold paid', gold($db, $p) === 86, 'gold ' . gold($db, $p));

    echo "== gates ==\n";
    $p = party($db, ['act_two_open' => 1, 'passage_booked' => 1, 'survey_fate' => 'took']);
    $n = (new DialogEngine($db))->node($p, 'dorcas_fenn');
    $labels = implode('|', array_map(fn ($c) => $c['label'], $n['choices'] ?? []));
    ok('no Fenmoor hook without the mark', !str_contains($labels, 'Fenmoor'), $labels);
    $p = party($db, $base, 5);
    trail($db, $p);
    $n = (new DialogEngine($db))->node($p, 'lise_fenner');
    $n = talk($db, $p, 'lise_fenner', ['four winters']);
    $labels = implode('|', array_map(fn ($c) => $c['label'], array_filter($n['choices'] ?? [], fn ($c) => empty($c['locked']))));
    ok('cannot pay the burying with five gold', !str_contains($labels, 'burying'), $labels);
} catch (Throwable $e) {
    ok('played without a dead end', false, $e->getMessage());
} finally {
    foreach ($made as [$pid, $cid]) {
        $db->prepare('DELETE FROM character_inventory WHERE character_id=?')->execute([$cid]);
        foreach (['party_quest_stages','party_quests','world_flags','character_party'] as $t) {
            $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$pid]);
        }
        $db->prepare('DELETE FROM parties WHERE id=?')->execute([$pid]);
        $db->prepare('DELETE FROM characters WHERE id=?')->execute([$cid]);
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail?1:0);
