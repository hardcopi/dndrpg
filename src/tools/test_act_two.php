<?php
/**
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
        (SELECT id FROM modules WHERE module_key='rivermark'), 'act two probe')")->execute();
    $pid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
            constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
            proficiency_bonus, is_active, gold, current_location_id)
        VALUES (?, 'Human', 'Fighter', 5, 6500, 14, 12, 14, 12, 12, 12, 44, 44, 16, 3, 1, ?,
                (SELECT id FROM locations WHERE location_key='packet_stair'))")
        ->execute(['_act_two_probe_' . bin2hex(random_bytes(3)), $gold]);
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
$base = ['act_two_open' => 1, 'passage_booked' => 1, 'passage_fate' => 'booked'];

try {
    echo "== took: field books, then cost the clearance ==\n";
    $p = party($db, $base + ['survey_fate' => 'took']);
    talk($db, $p, 'osgar_tull', ['Go aboard']);
    ok('Tull carries the party to Brackwater', here($db, $p) === 'brackwater_wharf', here($db, $p));
    ok('the meeting quest is open', qstatus($db, $p, 'the_quarterly_meeting') === 'active');
    talk($db, $p, 'abel_moss', ['looking for the house', 'Number Nine']);
    talk($db, $p, 'minna_crale', ['nine sheets']);
    ok('the branch quest starts', qstatus($db, $p, 'a_record_is_not_the_thing') === 'active');
    talk($db, $p, 'dorcas_fenn', ['Lay the survey', 'File it']);
    ok('filing it is recorded', flag($db, $p, 'field_books_fate') === 'filed');
    talk($db, $p, 'minna_crale', ['Go up']);
    $n = (new DialogEngine($db))->node($p, 'hollin_sare');
    ok('the chair knows the survey was filed', str_contains((string) $n['text'], 'filed a survey'), substr((string) $n['text'], -120));
    talk($db, $p, 'hollin_sare', ['Go on', 'cleared town', 'Cost it', 'Thank you']);
    ok('the act ends costed', qstatus($db, $p, 'the_quarterly_meeting') === 'completed' && flag($db, $p, 'meeting_fate') === 'costed');
    ok('clearance_costed is set', flag($db, $p, 'clearance_costed') === '1');

    echo "== burned: Pellow, then an objection minuted ==\n";
    $p = party($db, $base + ['survey_fate' => 'burned']);
    opening($db, $p);
    talk($db, $p, 'minna_crale', ['survey that burned']);
    talk($db, $p, 'mr_pellow', ['I heard', 'It was us']);
    ok('Pellow files the party\'s name', flag($db, $p, 'fire_named') === 'us');
    talk($db, $p, 'minna_crale', ['Go up']);
    talk($db, $p, 'hollin_sare', ['Go on', 'objection', "That's all"]);
    ok('the act ends minuted', flag($db, $p, 'meeting_fate') === 'minuted' && qstatus($db, $p, 'the_quarterly_meeting') === 'completed');

    echo "== sold: a clause, then the twelfth chair ==\n";
    $p = party($db, $base + ['survey_fate' => 'sold', 'corse_retains_us' => 1], 500);
    opening($db, $p);
    talk($db, $p, 'minna_crale', ['books', 'Let me see it', 'clause', 'Thank you']);
    ok('the first ending clause is written', flag($db, $p, 'retainer_fate') === 'clause');
    talk($db, $p, 'minna_crale', ['Go up']);
    talk($db, $p, 'hollin_sare', ['Go on', 'empty chair', 'pay the call', "Mrs Orme"]);
    ok('the party subscribes', flag($db, $p, 'we_are_subscribers') === '1' && flag($db, $p, 'meeting_fate') === 'subscribed');
    $gold = (int) $db->query("SELECT SUM(c.gold) FROM character_party cp JOIN characters c ON c.id=cp.character_id WHERE cp.party_id=$p")->fetchColumn();
    ok('the call is paid', $gold <= 200, "gold $gold");

    echo "== warned: Pyke at the yard, then walk out ==\n";
    $p = party($db, $base + ['survey_fate' => 'warned', 'rivermark_warned' => 1]);
    opening($db, $p);
    talk($db, $p, 'minna_crale', ['read aloud']);
    talk($db, $p, 'pyke_downriver', ['What do you need', 'go to the yard']);
    talk($db, $p, 'abel_moss', ["seen him work"]);
    ok('Pyke is hired', flag($db, $p, 'pyke_fate') === 'hired');
    talk($db, $p, 'minna_crale', ['Go up']);
    talk($db, $p, 'hollin_sare', ['Go on', "heard enough", 'stairs']);
    ok('the act ends walked', flag($db, $p, 'meeting_fate') === 'walked');

    echo "== the wrong branch's replies stay hidden ==\n";
    $p = party($db, $base + ['survey_fate' => 'warned']);
    opening($db, $p);
    $n = (new DialogEngine($db))->node($p, 'minna_crale');
    $labels = implode('|', array_map(fn ($c) => $c['label'], $n['choices']));
    ok('only the warned matter is offered', str_contains($labels, 'read aloud') && !str_contains($labels, 'nine sheets') && !str_contains($labels, 'burned'), $labels);
    $p = party($db, ['passage_booked' => 1]);
    $n = (new DialogEngine($db))->node($p, 'osgar_tull');
    $labels = implode('|', array_map(fn ($c) => $c['label'], $n['choices'] ?? []));
    ok('no boarding before Act I is over', !str_contains($labels, 'Go aboard'), $labels);
} catch (Throwable $e) {
    ok('played without a dead end', false, $e->getMessage());
} finally {
    foreach ($made as [$pid, $cid]) {
        foreach (['party_quest_stages','party_quests','world_flags','character_party'] as $t) {
            $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$pid]);
        }
        $db->prepare('DELETE FROM parties WHERE id=?')->execute([$pid]);
        $db->prepare('DELETE FROM characters WHERE id=?')->execute([$cid]);
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail?1:0);
