<?php
/**
 * Act II aftermath: companions read meeting_fate; Minna Crale calls in Kessa's favour.
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
        (SELECT id FROM modules WHERE module_key='rivermark'), 'aftermath probe')")->execute();
    $pid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
            constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
            proficiency_bonus, is_active, gold, current_location_id)
        VALUES (?, 'Human', 'Fighter', 5, 6500, 14, 12, 14, 12, 12, 12, 44, 44, 16, 3, 1, ?,
                (SELECT id FROM locations WHERE location_key='packet_stair'))")
        ->execute(['_aftermath_probe_' . bin2hex(random_bytes(3)), $gold]);
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
function ended(PDO $db, int $p, string $stage): void {
    (new Effects($db))->apply($p, [['start_quest' => 'the_quarterly_meeting'], ['quest_stage' => ['quest' => 'the_quarterly_meeting', 'stage' => $stage]]]);
}
function greet(PDO $db, int $p, string $npc): string { return (string) ((new DialogEngine($db))->node($p, $npc)['text'] ?? ''); }
$base = ['act_two_open' => 1, 'passage_booked' => 1, 'survey_fate' => 'took'];
try {
    echo "== companions read the meeting ==\n";
    // A party holds three companions, so the four costed lines are read by two parties.
    $want = ['costed' => ['sera' => 'invoice', 'kessa' => 'two pages', 'aldric' => 'burying ground'],
             'costed ' => ['ilse' => 'goblins'],
             'minuted' => ['sera' => 'Kept is how', 'aldric' => 'true record'],
             'subscribed' => ['kessa' => 'dividend', 'ilse' => 'subscribers'],
             'walked' => ['sera' => 'three words', 'ilse' => 'count something']];
    $stage = ['costed' => 'costed', 'costed ' => 'costed', 'minuted' => 'minuted', 'subscribed' => 'subscribed', 'walked' => 'walked'];
    foreach ($want as $fate => $who) {
        $p = party($db, $base);
        ended($db, $p, $stage[$fate]);
        ok("meeting ends $fate", flag($db, $p, 'meeting_fate') === trim($fate), (string) flag($db, $p, 'meeting_fate'));
        $cs = new CompanionService($db);
        foreach ($who as $c => $needle) {
            $cs->meet($p, $c); $cs->recruit($p, $c);
            $t = greet($db, $p, $c);
            ok("$c reads $fate", stripos($t, $needle) !== false, substr($t, 0, 120));
        }
    }

    echo "== Kessa's favour ==\n";
    $p = party($db, $base + ['house_favour_owed' => 1]);
    $n = (new DialogEngine($db))->node($p, 'minna_crale');
    ok('no call before the meeting is over', !str_contains((string) ($n['text'] ?? ''), 'blank'));
    ended($db, $p, 'minuted');
    talk($db, $p, 'minna_crale', ['What does the house want', 'aloud again']);
    ok('read again', flag($db, $p, 'kessa_favour_fate') === 'read_again' && flag($db, $p, 'house_favour_owed') === null);
    $cs = new CompanionService($db); $cs->meet($p, 'kessa'); $cs->recruit($p, 'kessa');
    ok('Kessa heard every name', str_contains(greet($db, $p, 'kessa'), 'Forty-one'));

    $p = party($db, $base + ['house_favour_owed' => 1]);
    ended($db, $p, 'walked');
    talk($db, $p, 'minna_crale', ['What does the house want', 'Leave the blank']);
    ok('refused keeps the favour owed', flag($db, $p, 'kessa_favour_fate') === 'refused' && flag($db, $p, 'house_favour_owed') === '1');
    $t = greet($db, $p, 'minna_crale');
    ok('and Minna does not ask twice', !str_contains($t, 'would like the day to be today'), substr($t, 0, 80));

    $p = party($db, $base);
    ended($db, $p, 'costed');
    ok('no favour, no call', !str_contains(greet($db, $p, 'minna_crale'), 'blank'));
} catch (Throwable $e) {
    ok('played without a dead end', false, $e->getMessage());
} finally {
    foreach ($made as [$pid, $cid]) {
        foreach (['party_companions'] as $t) { try { $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$pid]); } catch (Throwable $e) {} }
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
