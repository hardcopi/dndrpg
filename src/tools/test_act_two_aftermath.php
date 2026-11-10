<?php
/**
 * Act II aftermath: companions read meeting_fate; Minna Crale calls in Kessa's favour;
 * Reed, Ashe, Marrow, Finch and Mott Wenn hear the outcome in Rivermark; Sera is offered the second desk at Number Nine.
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

    echo "== Rivermark hears about the meeting ==\n";
    foreach (['costed' => 'held', 'minuted' => 'written down', 'subscribed' => 'Owners', 'walked' => 'walked out'] as $fate => $needle) {
        $p = party($db, $base); ended($db, $p, $fate);
        $t = greet($db, $p, 'captain_elowen');
        ok("Reed hears $fate", stripos($t, $needle) !== false, substr($t, 0, 100));
        ok("Reed says it once ($fate)", (function () use ($db, $p, $needle) {
            talk($db, $p, 'captain_elowen', ['']); return stripos(greet($db, $p, 'captain_elowen'), $needle) === false; })());
    }

    echo "== the rest of Rivermark hears ==\n";
    $heard = [
        'tibald_ashe'   => ['costed' => 'held for the quarter', 'minuted' => 'looked twice', 'subscribed' => 'For information', 'walked' => 'close of business'],
        'hollis_marrow' => ['costed' => 'what held does to a price', 'minuted' => 'keep paper', 'subscribed' => 'same trade now', 'walked' => 'never understanding them'],
        'garrow_finch'  => ['costed' => 'held debt is still a debt', 'minuted' => 'They keep beautifully', 'subscribed' => 'buy at a discount', 'walked' => 'a line lower'],
        'mott_wenn'     => ['costed' => 'Carried a letter up', 'minuted' => 'He won', 'subscribed' => 'one name', 'walked' => 'different on Tuesday'],
        'street_almoner' => ['costed' => 'into pencil', 'minuted' => 'fattest book', 'subscribed' => 'which page', 'walked' => 'room where they decide'],
        'pol_reddick'   => ['costed' => 'thirteen weeks', 'minuted' => 'weigh against it later', 'subscribed' => "It's the job", 'walked' => "couldn't weigh"],
        'brenna_smith'  => ['costed' => 'costing of removal for a forge', 'minuted' => 'smith win', 'subscribed' => 'buy streets', 'walked' => 'anywhere I owed'],
        'serjeant_ballow' => ['costed' => 'beds on a cart', 'minuted' => 'very glad it', 'subscribed' => 'gets to the west gate', 'walked' => 'walk out of something'],
        'tobin'         => ['costed' => 'second letter', 'minuted' => "I've been the somebody", 'subscribed' => 'Shareholders', 'walked' => 'four hundred occasions'],
        'sib_marrell'   => ['costed' => 'drains the same', 'minuted' => 'one afternoon', 'subscribed' => 'send you the bill', 'walked' => 'open the windows'],
        'street_seer'   => ['costed' => 'coming up the river', 'minuted' => 'keep it in the deck', 'subscribed' => "didn't see that one", 'walked' => 'pay me for it now'],
        'market_baker'  => ['costed' => 'one word of a song', 'minuted' => 'argues anyway', 'subscribed' => 'full price', 'walked' => 'regretted the walking'],
    ];
    foreach ($heard as $npc => $lines) {
        foreach ($lines as $fate => $needle) {
            $p = party($db, $base); ended($db, $p, $fate);
            $t = greet($db, $p, $npc);
            ok("$npc hears $fate", stripos($t, $needle) !== false, substr($t, 0, 100));
            talk($db, $p, $npc, ['']);
            ok("$npc says it once ($fate)", stripos(greet($db, $p, $npc), $needle) === false);
        }
    }
    $p = party($db, $base + ['quarry_fate' => 'pact']); ended($db, $p, 'subscribed');
    ok('Marrow in the lockup is not on the floor shaking hands', !str_contains(greet($db, $p, 'hollis_marrow'), 'same trade now'));
    $p = party($db, $base + ['town_standing' => 'fallen']); ended($db, $p, 'walked');
    ok('Tobin at the milestone with his panniers has no shop talk', !str_contains(greet($db, $p, 'tobin'), 'four hundred occasions'));
    $p = party($db, $base); ended($db, $p, 'walked');
    (new Effects($db))->apply($p, [['start_quest' => 'a_short_measure']]);
    ok('Nell with a short measure open talks about the flour, not the meeting', !str_contains(greet($db, $p, 'market_baker'), 'regretted the walking'));
    $p = party($db, $base); ended($db, $p, 'costed');
    (new Effects($db))->apply($p, [['start_quest' => 'the_watch_payroll'], ['quest_stage' => ['quest' => 'the_watch_payroll', 'stage' => 'ask_the_clerk']]]);
    ok('a quest at the second desk still comes first', str_contains(greet($db, $p, 'tibald_ashe'), 'watch roll'));

    echo "== the rest of the town, again ==\n";
    $more = [
        'alder_pyke' => ['costed' => 'same chain', 'minuted' => 'read the marks', 'subscribed' => 'one and threepence is yours', 'walked' => 'walked off a line'],
        'osric_apothecary' => ['costed' => "year's remedies", 'minuted' => 'what went wrong', 'subscribed' => 'fen-root beds', 'walked' => 'other way round'],
        'market_farmer' => ['costed' => 'paying themselves', 'minuted' => 'Lovely hand', 'subscribed' => 'price was poor', 'walked' => "I've looked"],
        'street_minstrel' => ['costed' => 'invoice', 'minuted' => 'exact words kept', 'subscribed' => 'Ballad or comic', 'walked' => 'writes itself'],
        'market_miner' => ['costed' => 'by the yard', 'minuted' => 'Wages column', 'subscribed' => 'Mind how you dig', 'walked' => 'docked'],
        'street_drover' => ['costed' => 'by the mile', 'minuted' => "oxen don't read", 'subscribed' => 'not for sale', 'walked' => 'Middle of a furrow'],
        'street_scholar' => ['costed' => 'what this town is', 'minuted' => 'primary source', 'subscribed' => 'Yes, do', 'walked' => 'records the room'],
        'street_stranger' => ['costed' => 'already moving', 'minuted' => 'alarming', 'subscribed' => "It's restful", 'walked' => 'most rooms'],
        'watch_relief' => ['costed' => 'shift held', 'minuted' => 'Our half', 'subscribed' => 'longer shift', 'walked' => 'Walked out halfway'],
        'watch_square' => ['costed' => 'sold less', 'minuted' => "square's calm", 'subscribed' => 'where to find me', 'walked' => 'what it costs'],
        'watch_street' => ['costed' => 'good oil', 'minuted' => 'lamp book', 'subscribed' => 'keep these lit', 'walked' => 'shakes his head'],
        'market_beggar' => ['costed' => 'I come cheap', 'minuted' => 'still waiting', 'subscribed' => 'spare a coin', 'walked' => 'Walk past me'],
        'market_jester' => ['costed' => 'adding it up', 'minuted' => 'hundred years', 'subscribed' => 'who he works for', 'walked' => 'goat'],
        'market_sweeper' => ['costed' => 'Put that in', 'minuted' => 'who swept', 'subscribed' => 'your cobbles', 'walked' => 'Horses.'],
        'watch_gate' => ['costed' => 'count roofs', 'minuted' => 'miss it if it stopped', 'subscribed' => 'see your face', 'walked' => 'come up here'],
    ];
    foreach ($more as $who => $lines) {
        foreach ($lines as $fate => $needle) {
            $p = party($db, $base + ['meeting_fate' => $fate]);
            ok("$who hears $fate", str_contains(greet($db, $p, $who), $needle), substr(greet($db, $p, $who), 0, 90));
            talk($db, $p, $who, [$who === 'osric_apothecary' ? 'Another time' : 'Leave']);
            ok("$who says it once", !str_contains(greet($db, $p, $who), $needle));
        }
    }
    foreach (['costed' => 'one season of water', 'minuted' => 'I never minded', 'subscribed' => 'owner on a boat', 'walked' => 'all there is to do'] as $fate => $needle) {
        $p = party($db, $base + ['meeting_fate' => $fate, 'room_fate' => 'work']);
        ok("Corbin, at the kegs, hears $fate", str_contains(greet($db, $p, 'inn_sleeper'), $needle), substr(greet($db, $p, 'inn_sleeper'), 0, 90));
        talk($db, $p, 'inn_sleeper', ['Leave']);
        ok('Corbin says it once', !str_contains(greet($db, $p, 'inn_sleeper'), $needle));
    }
    foreach (['paid', 'road'] as $gone) {
        $p = party($db, $base + ['meeting_fate' => 'walked', 'room_fate' => $gone]);
        ok("Corbin is not there to hear it ($gone)", !str_contains(greet($db, $p, 'inn_sleeper'), 'all there is to do'));
    }
    $p = party($db, $base + ['meeting_fate' => 'walked']);
    ok('Corbin asleep at the table hears nothing', !str_contains(greet($db, $p, 'inn_sleeper'), 'all there is to do'));
    $p = party($db, $base + ['meeting_fate' => 'costed', 'concern_answering' => 1]);
    ok("Pyke's chain is gone once the house is answering", !str_contains(greet($db, $p, 'alder_pyke'), 'same chain'));

    echo "== companions place the mark ==\n";
    $marks = [
        ['ilse', ['mark_seen_stone' => 1], 'only ever a surveyor'],
        ['kessa', ['mark_seen_paper' => 1], 'Black wax'],
        ['sera', ['mark_seen_book' => 1], 'kept them well'],
        ['aldric', ['indentured_fate' => 'bought'], 'harder prayer'],
        ['aldric', ['indentured_fate' => 'read'], 'Sixty men'],
        ['aldric', ['indentured_fate' => 'left'], 'only one saying it'],
    ];
    foreach ($marks as [$who, $flags, $needle]) {
        $p = party($db, $base + $flags);
        $cs = new CompanionService($db); $cs->meet($p, $who); $cs->recruit($p, $who);
        ok("$who: $needle", str_contains(greet($db, $p, $who), $needle), substr(greet($db, $p, $who), 0, 90));
        talk($db, $p, $who, ['Nothing']);
        ok("$who says it once", !str_contains(greet($db, $p, $who), $needle));
    }
    $p = party($db, ['mark_seen_paper' => 1]);
    $cs = new CompanionService($db); $cs->meet($p, 'kessa'); $cs->recruit($p, 'kessa');
    ok('before the Deepworks Kessa has nothing to place it against', !str_contains(greet($db, $p, 'kessa'), 'Black wax'));

    echo "== Sera's second offer ==\n";
    $sera = function (array $flags, int $approval) use ($db, $base) {
        $p = party($db, $base + $flags); ended($db, $p, 'minuted');
        $cs = new CompanionService($db); $cs->meet($p, 'sera'); $cs->recruit($p, 'sera');
        $now = (int) ($db->query("SELECT approval FROM party_companions pc WHERE pc.party_id=$p AND pc.companion_key='sera'")->fetchColumn());
        $cs->adjustApproval($p, 'sera', $approval - $now);
        return $p;
    };
    $status = fn ($p) => (string) $db->query("SELECT pc.status FROM party_companions pc WHERE pc.party_id=$p AND pc.companion_key='sera'")->fetchColumn();
    $p = $sera(['sera_post_fate' => 'stayed'], 80);
    ok('Minna offers the second desk', str_contains(greet($db, $p, 'minna_crale'), 'second desk'));
    talk($db, $p, 'minna_crale', ["Sera's to answer"]);
    ok('a trusted Sera declines', flag($db, $p, 'sera_second_offer') === 'declined' && $status($p) === 'active', $status($p));
    ok('and says so on the road', str_contains(greet($db, $p, 'sera'), 'Sixty a year'));
    ok('offer not repeated', !str_contains(greet($db, $p, 'minna_crale'), 'second desk'));

    $p = $sera(['sera_post_fate' => 'stayed'], 20);
    talk($db, $p, 'minna_crale', ["Sera's to answer"]);
    ok('a doubtful Sera takes the desk', flag($db, $p, 'sera_second_offer') === 'took' && $status($p) !== 'active', $status($p));
    ok('Dorcas Fenn has her downstairs', str_contains(greet($db, $p, 'dorcas_fenn'), 'Second desk'));

    $p = $sera(['sera_post_fate' => 'stayed'], 80);
    talk($db, $p, 'minna_crale', ["isn't for hire"]);
    ok('answering for her costs approval', flag($db, $p, 'sera_second_offer') === 'spoken_for');
    ok('and she says so', str_contains(greet($db, $p, 'sera'), 'stand beside me'));

    $p = $sera(['sera_post_fate' => 'retained'], 50);
    ok('retained: Minna has the eight lines', str_contains(greet($db, $p, 'minna_crale'), 'Eight lines'));
    $p = $sera(['sera_post_fate' => 'retained'], 50);
    talk($db, $p, 'minna_crale', ['Who initials']);
    ok('four people read every month', !str_contains(greet($db, $p, 'minna_crale'), 'Eight lines'));
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
