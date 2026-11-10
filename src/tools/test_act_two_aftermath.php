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

    echo "== Brackwater and Greyhythe after the meeting ==\n";
    $river = [
        'minna_crale' => ['costed' => 'figure will be honest', 'minuted' => 'I found I liked it', 'subscribed' => 'liked the light', 'walked' => 'Seven words'],
        'abel_moss' => ['costed' => "I'll see it's right", 'minuted' => 'particular about that', 'subscribed' => 'Same as ever', 'walked' => 'point of it'],
        'dorcas_fenn' => ['costed' => 'up the valley will ask', 'minuted' => 'kept a space on the shelf', 'subscribed' => "I've decided", 'walked' => 'short ones'],
        'osgar_tull' => ['costed' => 'I carry everything', 'minuted' => 'weighs the same', 'subscribed' => 'owner aboard', 'walked' => 'dropped his pen'],
        'jory_kemp' => ['costed' => 'named the place for that', 'minuted' => 'hoping for shouting', 'subscribed' => 'twelfth of it', 'walked' => "Don't tell Mrs Crale"],
        'gillian_petch' => ['costed' => 'stops being delighted', 'minuted' => 'Nobody told me not to', 'subscribed' => 'Do I curtsey', 'walked' => 'obviously has one'],
    ];
    foreach ($river as $who => $lines) {
        foreach ($lines as $fate => $needle) {
            $p = party($db, $base + ['meeting_fate' => $fate]);
            $db->prepare("INSERT INTO party_quests (party_id, quest_id, status) SELECT ?, id, 'completed' FROM quests WHERE quest_key='the_quarterly_meeting'")->execute([$p]);
            ok("$who: $fate", str_contains(greet($db, $p, $who), $needle), substr(greet($db, $p, $who), 0, 90));
            talk($db, $p, $who, [$who === 'gillian_petch' ? 'Nothing. Sorry' : 'Leave']);
            ok("$who says it once", !str_contains(greet($db, $p, $who), $needle));
        }
    }

    $p = party($db, $base + ['meeting_fate' => 'walked']);
    $db->prepare("INSERT INTO party_quests (party_id, quest_id, status) SELECT ?, id, 'completed' FROM quests WHERE quest_key='the_quarterly_meeting'")->execute([$p]);
    talk($db, $p, 'jory_kemp', ['Leave']);
    $after = greet($db, $p, 'jory_kemp');
    ok('Jory, after, no longer says nobody heard what', str_contains($after, 'no two of them the same') && !str_contains($after, 'Nobody heard what'), substr($after, 0, 90));

    echo "== the valley roads hear ==\n";
    $roads = [
        'joss_frayle' => ['Leave', ['costed' => 'more than mine', 'minuted' => "I'd have cut it", 'subscribed' => 'so much the ton', 'walked' => 'carry it anyway']],
        'sten_harrow' => ['The road', ['costed' => 'let alone', 'minuted' => 'written down at them', 'subscribed' => 'partly from you', 'walked' => 'only slower']],
        'tull_barrow' => ['Nothing', ['costed' => 'costed the grazing', 'minuted' => 'The price does', 'subscribed' => 'get used to it', 'walked' => 'better price']],
        'wick_lorrimer' => ['The road', ['costed' => 'goes on longer', 'minuted' => 'It still runs', 'subscribed' => 'for the water', 'walked' => 'ones who go']],
        'dorn_ackley' => ['Leave', ['costed' => 'see the figure', 'minuted' => 'eleven years ago', 'subscribed' => "I'd not want it", 'walked' => 'stays with you']],
        'gest_pyle' => ['Goodbye', ['costed' => "can't read", 'minuted' => 'The carrying', 'subscribed' => "Whoever's it is", 'walked' => 'the nerve']],
        'hesk_dray' => ['Fair enough', ['costed' => 'Six of mine', 'minuted' => 'under the sheets', 'subscribed' => 'start with you', 'walked' => 'another wagon']],
        'orm_lisser' => ['Nothing', ['costed' => 'by their sums', 'minuted' => 'wrote those down', 'subscribed' => 'share in somebody', 'walked' => 'keep forgetting']],
        'vessa_dann' => ["I'll watch", ['costed' => 'got a number', 'minuted' => 'pays out on', 'subscribed' => 'send someone', 'walked' => 'a forfeit']],
    ];
    foreach ($roads as $who => [$bye, $lines]) {
        foreach ($lines as $fate => $needle) {
            $p = party($db, $base + ['meeting_fate' => $fate]);
            ok("$who: $fate", str_contains(greet($db, $p, $who), $needle), substr(greet($db, $p, $who), 0, 90));
            talk($db, $p, $who, [$bye]);
            ok("$who says it once", !str_contains(greet($db, $p, $who), $needle));
        }
    }
    foreach ([['sten_harrow', ['harrow_gone' => 1], 'let alone'], ['wick_lorrimer', ['wick_in_town' => 1], 'goes on longer'], ['hesk_dray', ['salt_fate' => 'paid'], 'Six of mine']] as [$who, $gone, $needle]) {
        $p = party($db, $base + ['meeting_fate' => 'costed'] + $gone);
        ok("$who is not there to say it", !str_contains(greet($db, $p, $who), $needle));
    }

    echo "== the Deepworks hears ==\n";
    $works = [
        'bael_rourke' => ['costed' => 'It wasn\'t meant to', 'minuted' => 'every shift', 'subscribed' => 'wherever paper goes', 'walked' => 'coming down the stair'],
        'wenna_coyle' => ['costed' => 'a sheet for everything', 'minuted' => 'Welcome to it', 'subscribed' => "It's a short one", 'walked' => 'without one'],
        'corrin_dale' => ['costed' => 'halfpenny', 'minuted' => 'never been the trouble', 'subscribed' => 'Eat something', 'walked' => "That's the part"],
    ];
    foreach ($works as $who => $lines) {
        foreach ($lines as $fate => $needle) {
            $p = party($db, $base + ['meeting_fate' => $fate]);
            ok("$who: $fate", str_contains(greet($db, $p, $who), $needle), substr(greet($db, $p, $who), 0, 90));
            talk($db, $p, $who, ['Leave']);
            ok("$who says it once", !str_contains(greet($db, $p, $who), $needle));
        }
    }
    foreach ([['bael_rourke', ['office_taken' => 1], 'every shift'], ['bael_rourke', ['works_alarmed' => 1], 'every shift'], ['bael_rourke', ['office_fate' => 'took'], 'every shift'],
              ['wenna_coyle', ['works_alarmed' => 1], 'Welcome to it'], ['corrin_dale', ['indentured_fate' => 'bought'], 'never been the trouble']] as [$who, $gone, $needle]) {
        $p = party($db, $base + ['meeting_fate' => 'minuted'] + $gone);
        ok("$who stays quiet when not at his post (" . implode(',', array_keys($gone)) . ")", !str_contains(greet($db, $p, $who), $needle));
    }

    echo "== the costing ==\n";
    $flag = fn (int $p, string $k) => (string) ($db->query("SELECT flag_value FROM world_flags WHERE party_id = $p AND flag_key = " . $db->quote($k))->fetchColumn() ?: '');
    $at = function (int $p, string $locKey) use ($db): array {
        global $made; $cid = 0; foreach ($made as [$pp, $cc]) { if ($pp === $p) { $cid = $cc; } }
        $db->prepare("UPDATE characters SET current_location_id = (SELECT id FROM locations WHERE location_key = ?) WHERE id = ?")->execute([$locKey, $cid]);
        $state = (new LocationEngine($db))->getState($cid, $p)['location'] ?? [];
        return array_column($state['npcs'] ?? [], 'npc_key');
    };
    $p = party($db, $base + ['meeting_fate' => 'minuted']);
    ok('no assessor on Granary Row unless the meeting costed it', !in_array('hesper_amsel', $at($p, 'granary_row'), true));
    $p = party($db, $base + ['meeting_fate' => 'costed', 'clearance_costed' => 1]);
    ok('a costed meeting sends Hesper Amsel to Granary Row', in_array('hesper_amsel', $at($p, 'granary_row'), true));
    $before = (int) $flag($p, 'concern_pressure');
    talk($db, $p, 'hesper_amsel', ['What are you counting', 'Some things', 'Leave her to count']);
    ok('counting the whole of it ends the_costing as counted_whole', $flag($p, 'costing_fate') === 'whole');
    ok('a sixth head costs the house 2', (int) $flag($p, 'concern_pressure') === $before + 2, "$before -> " . $flag($p, 'concern_pressure'));
    ok('Hesper afterwards: six heads', str_contains(greet($db, $p, 'hesper_amsel'), 'Six heads'));
    ok('Osric heard he was written down', str_contains(greet($db, $p, 'osric_apothecary'), 'by weight'));
    ok('Dagen Wyke has the nine in ink', str_contains(greet($db, $p, 'street_almoner'), 'in ink now'));
    $db->prepare("INSERT INTO party_quests (party_id, quest_id, status) SELECT ?, id, 'completed' FROM quests WHERE quest_key='the_quarterly_meeting'")->execute([$p]);
    talk($db, $p, 'minna_crale', ['Leave']);
    ok('Minna Crale has the six heads', str_contains(greet($db, $p, 'minna_crale'), 'Six heads'), substr(greet($db, $p, 'minna_crale'), 0, 90));
    $p = party($db, $base + ['meeting_fate' => 'costed', 'clearance_costed' => 1]);
    $db->prepare("INSERT INTO party_quests (party_id, quest_id, status) SELECT ?, id, 'completed' FROM quests WHERE quest_key='the_quarterly_meeting'")->execute([$p]);
    talk($db, $p, 'hesper_amsel', ['What are you counting', 'Put down double', 'Count it your way', 'Leave her to it']);
    ok('asking to pad it is refused and remembered', $flag($p, 'costing_padding_asked') === '1' && $flag($p, 'costing_fate') === 'given');
    ok('she says so once', str_contains(greet($db, $p, 'hesper_amsel'), 'double it, once'));
    talk($db, $p, 'hesper_amsel', ['Leave']);
    ok('then the five-heads ending', str_contains(greet($db, $p, 'hesper_amsel'), 'Nine days'));
    ok('Dagen: no head for the families', str_contains(greet($db, $p, 'street_almoner'), 'still in pencil'));
    talk($db, $p, 'minna_crale', ['Leave']);
    ok('Minna Crale: five heads, every line true', str_contains(greet($db, $p, 'minna_crale'), 'every line true'), substr(greet($db, $p, 'minna_crale'), 0, 90));

    echo "== the other three outcomes change things ==\n";
    $done = function (int $p) use ($db) { $db->prepare("INSERT INTO party_quests (party_id, quest_id, status) SELECT ?, id, 'completed' FROM quests WHERE quest_key='the_quarterly_meeting'")->execute([$p]); };
    foreach (['against' => ['Lodge it now', 'Entered.'], 'kept' => ["I'll keep it", 'notice was given'], 'proxy' => ['Write a proxy', 'place under discussion']] as $fate => [$label, $needle]) {
        $p = party($db, $base + ['meeting_fate' => 'subscribed']); $done($p);
        talk($db, $p, 'minna_crale', ['Leave']);
        ok("subscribed: Minna has the notice ($fate)", str_contains(greet($db, $p, 'minna_crale'), 'Notice of the quarter-day'), substr(greet($db, $p, 'minna_crale'), 0, 90));
        $node = talk($db, $p, 'minna_crale', [$label]);
        ok("vote $fate: $needle", str_contains((string) ($node['text'] ?? ''), $needle) && $flag($p, 'vote_fate') === $fate, substr((string) ($node['text'] ?? ''), 0, 90));
        ok("no second notice after $fate", !str_contains(greet($db, $p, 'minna_crale'), 'Notice of the quarter-day'));
    }
    ok('Reed holds the proxy', str_contains(greet($db, $p, 'captain_elowen'), 'twelfth of the people buying it'));
    $p = party($db, $base + ['meeting_fate' => 'subscribed']); $done($p);
    talk($db, $p, 'minna_crale', ['Leave']); talk($db, $p, 'minna_crale', ['Let me think']);
    ok('thinking about it leaves the vote open', str_contains(greet($db, $p, 'minna_crale'), 'Item four') && $flag($p, 'vote_fate') === '');
    talk($db, $p, 'minna_crale', ['Lodge it now']);
    ok('and it can be lodged later', $flag($p, 'vote_fate') === 'against');
    talk($db, $p, 'mara_hearthstone', ['Leave']);
    ok('Mara heard it was lodged', str_contains(greet($db, $p, 'mara_hearthstone'), 'Lodged it early'));

    foreach (['copied' => 'Copy it out', 'shelved' => 'Leave it on the shelf'] as $fate => $label) {
        $p = party($db, $base + ['meeting_fate' => 'minuted']);
        talk($db, $p, 'dorcas_fenn', ['Leave']);
        ok("minuted: the bound volume is in ($fate)", str_contains(greet($db, $p, 'dorcas_fenn'), 'Bound this morning'), substr(greet($db, $p, 'dorcas_fenn'), 0, 90));
        $node = talk($db, $p, 'dorcas_fenn', ['Read it']);
        ok('the minute reads: noted, no resolution', str_contains((string) ($node['text'] ?? ''), 'No resolution was moved'));
        talk($db, $p, 'dorcas_fenn', ['Read it', $label]);
        ok("minute_fate $fate", $flag($p, 'minute_fate') === $fate);
    }
    ok('Moss read the shelved minute', str_contains(greet($db, $p, 'dorcas_fenn'), 'dinner hour'), substr(greet($db, $p, 'dorcas_fenn'), 0, 90));
    $p = party($db, $base + ['meeting_fate' => 'minuted', 'minute_fate' => 'copied']);
    talk($db, $p, 'mara_hearthstone', ['Leave']);
    ok('the copy is on the Flagon door', str_contains(greet($db, $p, 'mara_hearthstone'), 'Flagon door'));

    foreach (['withdrawn' => ['Write back: withdrawn', 'inked through'], 'reasons' => ['say why I walked out', 'warm room'], 'stands' => ['Let it stand', 'in pencil, and your name']] as $fate => [$label, $needle]) {
        $p = party($db, $base + ['meeting_fate' => 'walked']); $done($p);
        talk($db, $p, 'mara_hearthstone', ['Leave']);
        ok("walked: a letter care of the Flagon ($fate)", str_contains(greet($db, $p, 'mara_hearthstone'), 'Item seven stands adjourned'), substr(greet($db, $p, 'mara_hearthstone'), 0, 90));
        talk($db, $p, 'mara_hearthstone', [$label]);
        ok("walked_fate $fate", $flag($p, 'walked_fate') === $fate);
        ok('the letter does not come twice', !str_contains(greet($db, $p, 'mara_hearthstone'), 'Item seven stands adjourned'));
        talk($db, $p, 'minna_crale', ['Leave']);
        ok("Minna on $fate", str_contains(greet($db, $p, 'minna_crale'), $needle), substr(greet($db, $p, 'minna_crale'), 0, 90));
    }

    echo "== the crate lid ==\n";
    $lid = (int) $db->query("SELECT COUNT(*) FROM location_items li JOIN items i ON i.id = li.item_id JOIN locations l ON l.id = li.location_id WHERE i.item_key = 'fenmoor_crate_lid' AND l.location_key = 'warren_stores'")->fetchColumn();
    ok('the lid lies in the warren stores, to be found by searching', $lid === 1);
    $p = party($db, $base + ['armour_chain_ended' => 1, 'armour_chain_fate' => 'left']);
    ok('nobody mentions a lid you have not found', !str_contains(greet($db, $p, 'lise_fenner'), "That's Abe's"));
    (new Effects($db))->apply($p, [['give_item' => 'fenmoor_crate_lid']]);
    foreach (['dorcas_fenn' => 'lid seen', 'hester_brindle' => "sale-room's stencil", 'lise_fenner' => "That's Abe's", 'coll_darrow' => 'one of the four', 'nakka' => 'paid not to wonder'] as $who => $needle) {
        ok("$who knows the lid", str_contains(greet($db, $p, $who), $needle), substr(greet($db, $p, $who), 0, 90));
        talk($db, $p, $who, ['Leave']);
        ok("$who says it once", !str_contains(greet($db, $p, $who), $needle));
    }
    $p = party($db, $base);
    (new Effects($db))->apply($p, [['give_item' => 'fenmoor_crate_lid']]);
    ok('before the Fenmoor chain the lid is just wood to Dorcas', !str_contains(greet($db, $p, 'dorcas_fenn'), 'lid seen'));

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
    echo "== companions on the vote, the minute and item seven ==\n";
    $beats = [
        ['vote_fate', 'against', 'sera', 'lodged it early'], ['vote_fate', 'kept', 'sera', "I'd have done"],
        ['vote_fate', 'proxy', 'sera', 'watch captain'], ['costing_fate', 'whole', 'sera', 'sixth head'],
        ['minute_fate', 'copied', 'aldric', 'Flagon door'], ['minute_fate', 'shelved', 'aldric', 'dividend and the drains'],
        ['walked_fate', 'reasons', 'aldric', 'said it to them'],
        ['walked_fate', 'withdrawn', 'kessa', 'clean'], ['walked_fate', 'stands', 'kessa', 'in pencil'],
        ['walked_fate', 'reasons', 'kessa', 'Explaining'], ['vote_fate', 'proxy', 'kessa', 'Huh'],
        ['vote_fate', 'proxy', 'ilse', 'good coat'],
    ];
    foreach ($beats as [$k, $v, $c, $needle]) {
        $p = party($db, $base + [$k => $v]); ended($db, $p, 'costed');
        $cs = new CompanionService($db); $cs->meet($p, $c); $cs->recruit($p, $c);
        $t = greet($db, $p, $c);
        ok("$c on $k=$v", stripos($t, $needle) !== false, substr($t, 0, 100));
        $n = (new DialogEngine($db))->node($p, $c);
        talk($db, $p, $c, [(string) end($n['choices'])['label']]);
        ok("$c says it once ($v)", stripos(greet($db, $p, $c), $needle) === false);
    }

    echo "== the house hears the costing and the vote ==\n";
    foreach ([['one_vote_in_twelve', 'lodged_against', 1], ['one_vote_in_twelve', 'proxy_to_reed', 2], ['one_vote_in_twelve', 'kept_vote', 0], ['the_bound_minute', 'copied', 1], ['the_bound_minute', 'shelved', 0]] as [$q, $st, $n]) {
        $p = party($db, $base); ended($db, $p, $q === 'the_bound_minute' ? 'minuted' : 'subscribed');
        $before = (int) flag($db, $p, 'concern_pressure');
        (new Effects($db))->apply($p, [['start_quest' => $q], ['quest_stage' => ['quest' => $q, 'stage' => $st]]]);
        $after = (int) flag($db, $p, 'concern_pressure');
        ok("$q/$st moves the house by $n", $after === $before + $n, "$before -> $after");
    }
    foreach ([['costing_fate', 'whole', 'six heads'], ['costing_fate', 'given', 'Five heads'], ['vote_fate', 'against', 'Against'], ['vote_fate', 'kept', 'not yet spent'], ['vote_fate', 'proxy', 'good coat']] as [$k, $v, $needle]) {
        $p = party($db, $base + [$k => $v]); ended($db, $p, 'costed');
        $t = greet($db, $p, 'ludo_corse');
        ok("Corse hears $k=$v", stripos($t, $needle) !== false, substr($t, 0, 100));
        talk($db, $p, 'ludo_corse', ['Leave']);
        ok("Corse says it once ($v)", stripos(greet($db, $p, 'ludo_corse'), $needle) === false);
    }
    echo "== the uncounted ==\n";
    $dw = $base + ['deepworks_known' => 1];
    $p = party($db, $dw);
    ok('no page before the meeting', !str_contains(greet($db, $p, 'osgar_tull'), 'copied it instead'));
    $p = party($db, $dw + ['works_alarmed' => 1]); ended($db, $p, 'costed'); talk($db, $p, 'osgar_tull', ['Leave']);
    ok('no page once the works are alarmed', !str_contains(greet($db, $p, 'osgar_tull'), 'copied it instead'));
    $begin = function () use ($db, $dw) {
        $p = party($db, $dw); ended($db, $p, 'costed');
        talk($db, $p, 'osgar_tull', ['Leave']);
        return $p;
    };
    $p = $begin();
    ok('Tull has copied the page', str_contains(greet($db, $p, 'osgar_tull'), 'copied it instead'));
    talk($db, $p, 'osgar_tull', ['take it down']);
    ok('the uncounted starts', qstatus($db, $p, 'the_uncounted') === 'active');
    ok('Coyle lays it against the board', str_contains(greet($db, $p, 'wenna_coyle'), 'Nineteen of his'));
    $before = (int) flag($db, $p, 'concern_pressure');
    talk($db, $p, 'wenna_coyle', ['Struck', 'And then', 'Read the fifteen']);
    ok('read in the canteen', flag($db, $p, 'uncounted_fate') === 'read' && qstatus($db, $p, 'the_uncounted') === 'completed');
    ok('reading them costs the house 1', (int) flag($db, $p, 'concern_pressure') === $before + 1);
    ok('Coyle made a column', str_contains(greet($db, $p, 'wenna_coyle'), 'made a column'));
    ok('Tull hears it', str_contains(greet($db, $p, 'osgar_tull'), 'canteen'));
    ok('Dale sleeps in his bunk', str_contains(greet($db, $p, 'corrin_dale'), 'Peg sixty-one'));
    $cs = new CompanionService($db); $cs->meet($p, 'kessa'); $cs->recruit($p, 'kessa'); $cs->meet($p, 'ilse'); $cs->recruit($p, 'ilse');
    ok('Kessa on the names that were not names', str_contains(greet($db, $p, 'kessa'), 'John Stair'));
    ok('Ilse on the tally', str_contains(greet($db, $p, 'ilse'), 'keep one on the men'));
    ok('nothing names who asked', !preg_match('/who asked/i', file_get_contents(__DIR__ . '/../content/quests/the_uncounted.json')));

    $p = $begin(); talk($db, $p, 'osgar_tull', ['take it down']);
    talk($db, $p, 'wenna_coyle', ['Struck', 'And then', 'Record Office']);
    ok('to the Record Office', qstatus($db, $p, 'the_uncounted') === 'active' && str_contains(greet($db, $p, 'dorcas_fenn'), 'no head for this'));
    $before = (int) flag($db, $p, 'concern_pressure');
    talk($db, $p, 'dorcas_fenn', ['Enter it']);
    ok('entered as given', flag($db, $p, 'uncounted_fate') === 'entered' && (int) flag($db, $p, 'concern_pressure') === $before + 1);
    ok('someone comes to read it', str_contains(greet($db, $p, 'dorcas_fenn'), 'looking for a name'));
    $cs = new CompanionService($db); $cs->meet($p, 'aldric'); $cs->recruit($p, 'aldric');
    ok('Aldric: like a parish', str_contains(greet($db, $p, 'aldric'), 'like a parish'));

    $p = $begin(); talk($db, $p, 'osgar_tull', ['take it down']);
    $before = (int) flag($db, $p, 'concern_pressure');
    talk($db, $p, 'wenna_coyle', ['Struck', 'And then', 'Fold it away']);
    ok('kept costs the house nothing', flag($db, $p, 'uncounted_fate') === 'kept' && (int) flag($db, $p, 'concern_pressure') === $before);
    $cs = new CompanionService($db); $cs->meet($p, 'sera'); $cs->recruit($p, 'sera');
    ok('Sera: worse on a canal', str_contains(greet($db, $p, 'sera'), 'canal'));
    ok('Tull will not ask', str_contains(greet($db, $p, 'osgar_tull'), "I'll not ask"));
    echo "== the house answers the uncounted ==\n";
    foreach ([['read', 'concern_answering', 'wenna_coyle', 'Place of burial'], ['entered', 'concern_answering', 'wenna_coyle', 'Place of burial'],
              ['entered', 'concern_answering', 'dorcas_fenn', 'closed to inspection'], ['entered', 'concern_asking', 'dorcas_fenn', 'fee for a correction'],
              ['read', 'concern_answering', 'ludo_corse', 'unhelpful'], ['entered', 'concern_answering', 'ludo_corse', 'unhelpful'],
              ['read', 'concern_asking', 'garrow_finch', 'canteen']] as [$fate, $tier, $npc, $needle]) {
        $fl = $dw + ['uncounted_fate' => $fate, 'concern_noticed' => 1, 'concern_asking' => 1] + ($tier === 'concern_answering' ? ['concern_answering' => 1] : []);
        $p = party($db, $fl); ended($db, $p, 'walked');
        for ($k = 0; $k < 3 && stripos(greet($db, $p, $npc), $needle) === false; $k++) {
            $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        }
        ok("$npc answers $fate at $tier", stripos(greet($db, $p, $npc), $needle) !== false, substr(greet($db, $p, $npc), 0, 90));
        $p = party($db, $dw + ['uncounted_fate' => 'kept', $tier => 1, 'concern_asking' => 1]); ended($db, $p, 'walked');
        $seen = false;
        for ($k = 0; $k < 4; $k++) { if (stripos(greet($db, $p, $npc), $needle) !== false) $seen = true; $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]); }
        ok("but not if the count was kept ($npc)", !$seen);
    }

    echo "== Corse's figure reaches Minna ==\n";
    foreach ([['Send it round', 'circulated', 1, 'Three of them', 'sera', 'misprint'], ['read first', 'read_first', 0, 'reading voice', 'sera', 'get used to a number']] as [$pick, $fate, $dp, $corse, $c, $cl]) {
        $p = party($db, $base + ['clearance_costed' => 1]); ended($db, $p, 'costed');
        (new Effects($db))->apply($p, [['start_quest' => 'the_costing'], ['quest_stage' => ['quest' => 'the_costing', 'stage' => 'counted_given']]]);
        $k = 0; while ($k++ < 4 && !str_contains(greet($db, $p, 'minna_crale'), "Mr Corse's figure")) { $n = (new DialogEngine($db))->node($p, 'minna_crale'); talk($db, $p, 'minna_crale', [(string) end($n['choices'])['label']]); }
        ok("Corse's figure reaches Minna ($fate)", str_contains(greet($db, $p, 'minna_crale'), "Mr Corse's figure"));
        $before = (int) flag($db, $p, 'concern_pressure');
        talk($db, $p, 'minna_crale', ['What happens', $pick]);
        ok("figure $fate", flag($db, $p, 'costing_figure') === $fate && (int) flag($db, $p, 'concern_pressure') === $before + $dp);
        ok("Minna does not ask twice ($fate)", !str_contains(greet($db, $p, 'minna_crale'), "Mr Corse's figure"));
        $k = 0; while ($k++ < 4 && !str_contains(greet($db, $p, 'ludo_corse'), $corse)) { $n = (new DialogEngine($db))->node($p, 'ludo_corse'); talk($db, $p, 'ludo_corse', [(string) end($n['choices'])['label']]); }
        ok("Corse hears ($fate)", str_contains(greet($db, $p, 'ludo_corse'), $corse));
        $cs = new CompanionService($db); $cs->meet($p, $c); $cs->recruit($p, $c);
        ok("$c on the figure ($fate)", str_contains(greet($db, $p, $c), $cl), substr(greet($db, $p, $c), 0, 80));
    }
    echo "== the quarry endings ==\n";
    $lines = ['filed' => [['gest_pyle', 'town\'s book'], ['dorn_ackley', 'stopped being ours'], ['tibald_ashe', 'east quarry']],
              'stone' => [['gest_pyle', 'two drovers'], ['dorn_ackley', 'argued with it'], ['alder_pyke', 'argument on it']],
              'sold'  => [['gest_pyle', 'checking his change'], ['dorn_ackley', 'ask the stone']]];
    foreach ($lines as $fate => $who) foreach ($who as [$npc, $needle]) {
        $p = party($db, $base + ['quarry_writ_fate' => $fate, 'mark_seen_writ' => 1]); ended($db, $p, 'walked');
        $hit = false;
        for ($k = 0; $k < 4; $k++) { if (stripos(greet($db, $p, $npc), $needle) !== false) { $hit = true; break; } $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]); }
        ok("$npc reads quarry $fate", $hit);
        if ($hit) { $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]); }
        ok("$npc says it once ($fate)", stripos(greet($db, $p, $npc), $needle) === false);
    }
    foreach (['filed' => 'margin', 'stone' => 'two of it'] as $fate => $needle) {
        $p = party($db, $base + ['quarry_writ_fate' => $fate, 'clearance_costed' => 1]); ended($db, $p, 'costed');
        (new Effects($db))->apply($p, [['start_quest' => 'the_costing']]);
        ok("Hesper counts the quarry ($fate)", stripos(greet($db, $p, 'hesper_amsel'), $needle) !== false, substr(greet($db, $p, 'hesper_amsel'), 0, 80));
        talk($db, $p, 'hesper_amsel', ['Count it your way', 'Leave her to it']);
        ok("and the costing still goes on ($fate)", flag($db, $p, 'costing_fate') === 'given');
    }
    echo "== the priory, the reliquary and the arch ==\n";
    $seek = function (int $p, string $npc, string $needle) use ($db): bool {
        for ($k = 0; $k < 5; $k++) { if (stripos(greet($db, $p, $npc), $needle) !== false) return true; $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]); }
        return false;
    };
    $p = party($db, $base + ['priory_fate' => 'unentered']);
    ok('the prior noticed nobody asked', $seek($p, 'prioress_hale', 'without asking'));
    talk($db, $p, 'prioress_hale', ['Goodbye']);
    ok('the prior says it once', !$seek($p, 'prioress_hale', 'without asking'));
    $p = party($db, $base + ['sealed_arch_fate' => 'left']);
    ok('Aggie: now there are two', $seek($p, 'aggie_slate', 'Now there are two'));
    foreach (['priory_fate' => ['unentered', 'did not ask her'], 'reliquary_fate' => ['sold', 'own memory'], 'reliquary_fate ' => ['paper', 'decade of Arden']] as $k => [$v, $needle]) {
        $p = party($db, $base + [trim($k) => $v]); ended($db, $p, 'walked');
        $cs = new CompanionService($db); $cs->meet($p, 'aldric'); $cs->recruit($p, 'aldric');
        ok("Aldric on $k=$v", str_contains(greet($db, $p, 'aldric'), $needle), substr(greet($db, $p, 'aldric'), 0, 80));
    }
    $p = party($db, $base + ['sold_to_the_polite_man' => 1]); ended($db, $p, 'walked');
    $cs = new CompanionService($db); $cs->meet($p, 'sera'); $cs->recruit($p, 'sera');
    ok("Sera on the man who doesn't haggle", str_contains(greet($db, $p, 'sera'), "doesn't haggle"));
    $p = party($db, $base + ['mearing_recut' => 1, 'quarry_writ_fate' => 'stone']); ended($db, $p, 'walked');
    ok('the disputed sheet reaches Dorcas', $seek($p, 'dorcas_fenn', 'Disputed'));

    $p = party($db, $base + ['reliquary_fate' => 'paper', 'cartulary_held' => 1, 'priory_paper_kept' => 1]); ended($db, $p, 'walked');
    (new Effects($db))->apply($p, [['give_item' => 'priory_cartulary', 'quantity' => 1]]);
    ok('Dorcas offers to take a deposit', $seek($p, 'dorcas_fenn', 'takes deposits'));
    talk($db, $p, 'dorcas_fenn', ['Enter it as']);
    $has = (int) $db->query("SELECT COUNT(*) FROM character_inventory ci JOIN items i ON i.id=ci.item_id JOIN character_party cp ON cp.character_id=ci.character_id WHERE cp.party_id=$p AND i.item_key='priory_cartulary'")->fetchColumn();
    ok('deposited: the press leaves the pack', flag($db, $p, 'cartulary_deposited') === '1' && $has === 0, "has=$has");
    ok('Venn has the letter', $seek($p, 'almoner_venn', 'first good letter'));
    $p = party($db, $base + ['reliquary_fate' => 'paper', 'cartulary_held' => 1]); ended($db, $p, 'walked');
    (new Effects($db))->apply($p, [['give_item' => 'priory_cartulary', 'quantity' => 1]]);
    $seek($p, 'dorcas_fenn', 'takes deposits'); talk($db, $p, 'dorcas_fenn', ["I'll keep it"]);
    ok('kept: asked once', flag($db, $p, 'cartulary_deposited') === 'kept' && !$seek($p, 'dorcas_fenn', 'takes deposits'));

    echo "== the circulated figure goes up the valley ==\n";
    foreach ([[['costing_figure' => 'circulated', 'costing_fate' => 'whole'], 'captain_elowen', 'read them the bread'],
              [['costing_figure' => 'circulated', 'costing_fate' => 'whole'], 'hesper_amsel', 'usual head'],
              [['costing_figure' => 'circulated', 'costing_fate' => 'given'], 'hesper_amsel', 'all I was given'],
              [['costing_figure' => 'read_first', 'costing_fate' => 'given'], 'hesper_amsel', 'interrupt'],
              [['costing_figure' => 'read_first', 'costing_fate' => 'given'], 'captain_elowen', '__never__']] as [$fl, $npc, $needle]) {
        $p = party($db, $base + $fl + ['clearance_costed' => 1]); ended($db, $p, 'costed');
        $got = $seek($p, $npc, $needle);
        ok("$npc / " . implode(',', $fl), $needle === '__never__' ? !$got : $got);
    }
    echo "== quarter-day eve ==\n";
    $eve = function (array $fl, string $fate) use ($db, $base, $seek) {
        $p = party($db, $base + $fl); ended($db, $p, $fate);
        $found = $seek($p, 'mara_hearthstone', 'Last packet that makes');
        $texts = [];
        if ($found) {
            $de = new DialogEngine($db); $n = $de->node($p, 'mara_hearthstone'); $key = (string) ($n['node_key'] ?? $n['key'] ?? 'greeting');
            $r = $de->choose($p, 'mara_hearthstone', $key, 0);
            for ($k = 0; $k < 6 && isset($r['node']); $k++) {
                $texts[] = (string) $r['node']['text'];
                $nk = (string) ($r['node']['node_key'] ?? $r['node']['key'] ?? '');
                $r = (new DialogEngine($db))->choose($p, 'mara_hearthstone', $nk, 0);
            }
        }
        return [$p, $found, implode(' || ', $texts)];
    };
    [$p, $f] = $eve([], 'costed');
    ok('no eve without anything set up', !$f);
    foreach ([[['vote_fate' => 'against'], 'subscribed', 'OF 12'], [['vote_fate' => 'proxy'], 'subscribed', 'Dawn packet'],
              [['minute_fate' => 'copied'], 'minuted', 'crosses'], [['walked_fate' => 'stands'], 'walked', 'to the pencil'],
              [['costing_figure' => 'circulated'], 'costed', 'burying ground twice'], [['costing_figure' => 'read_first'], 'costed', 'coffin'],
              [['vote_fate' => 'kept', 'uncounted_fate' => 'entered', 'cartulary_deposited' => '1'], 'subscribed', 'Mrs Fenn'],
              [['vote_fate' => 'kept', 'uncounted_fate' => 'read', 'quarry_writ_fate' => 'filed'], 'subscribed', 'back of his hand'],
              [['uncounted_fate' => 'entered'], 'walked', 'warm room']] as [$fl, $fate, $needle]) {
        [$p, $f, $t] = $eve($fl, $fate);
        ok('eve: ' . implode(',', array_keys($fl)) . " → $needle", $f && str_contains($t, $needle), substr($t, 0, 160));
        ok('the vote is still open on the eve (' . implode(',', array_keys($fl)) . ')', str_contains($t, 'has not been decided yet') && flag($db, $p, 'quarter_eve') === '1');
        ok('the eve happens once', !$seek($p, 'mara_hearthstone', 'Last packet that makes'));
    }

    echo "== later visitors ==\n";
    $p = party($db, $base + ['cartulary_deposited' => '1', 'quarter_eve' => 1, 'cartulary_held' => 1]); ended($db, $p, 'walked');
    ok('a clerk read the Arden bounds', $seek($p, 'dorcas_fenn', 'only the bounds'));
    ok('the prior will consider it', $seek($p, 'almoner_venn', 'would consider it'));
    $p = party($db, $base + ['cartulary_deposited' => '1', 'cartulary_held' => 1]); ended($db, $p, 'walked');
    ok('not before the eve', !$seek($p, 'dorcas_fenn', 'only the bounds'));
    $p = party($db, $base + ['mearing_recut' => 1, 'quarter_eve' => 1]); ended($db, $p, 'walked');
    ok('a widow read the Disputed shelf', $seek($p, 'dorcas_fenn', 'which was true'));
    echo "== the valley's loose ends are heard of ==\n";
    foreach ([['fen_road_closing', 'captain_elowen', 'four points'], ['north_ford_open', 'captain_elowen', 'north ford'],
              ['warren_fed', 'serjeant_ballow', 'Eleven wagons'], ['ford_road_open', 'serjeant_ballow', 'laid stone'],
              ['fen_trade_on_paper', 'osric_apothecary', 'both sides meant'], ['fen_draining', 'osric_apothecary', 'beds are drying'],
              ['fen_road_open_paid', 'osric_apothecary', 'without a story'], ['mill_race_right_common', 'tibald_ashe', 'no longer one man']] as [$fl, $npc, $needle]) {
        $p = party($db, $base + [$fl => 1]); ended($db, $p, 'walked');
        ok("$npc reads $fl", $seek($p, $npc, $needle));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("once ($fl)", !$seek($p, $npc, $needle));
    }
    echo "== companions on the eve; the packet at first light ==\n";
    $play = function (int $p, string $npc, int $steps = 6) use ($db) {
        $de = new DialogEngine($db); $n = $de->node($p, $npc); $key = (string) ($n['node_key'] ?? $n['key'] ?? 'greeting');
        $out = []; $r = $de->choose($p, $npc, $key, 0);
        for ($k = 0; $k < $steps && isset($r['node']); $k++) {
            $out[] = (string) $r['node']['text'];
            foreach ($r['node']['interjections'] ?? [] as $ij) $out[] = '[' . $ij['companion'] . '] ' . $ij['text'];
            $nk = (string) ($r['node']['node_key'] ?? $r['node']['key'] ?? '');
            $r = (new DialogEngine($db))->choose($p, $npc, $nk, 0);
        }
        return implode(' || ', $out);
    };
    foreach ([[['vote_fate' => 'against'], 'subscribed', ['sera', 'kessa'], ['[sera] Sera, low', '[kessa] Kessa reads SO FAR', '[sera] Sera, at the stair', '[kessa] Kessa, at the stair']],
              [['vote_fate' => 'proxy'], 'subscribed', ['ilse', 'sera'], ['[ilse] Ilse watches Reed', 'only person in that room', '[ilse] Ilse, at the stair']],
              [['minute_fate' => 'copied', 'uncounted_fate' => 'entered'], 'minuted', ['aldric'], ['takes his hat off', 'made them a parish', 'compline for the room']],
              [['walked_fate' => 'stands', 'uncounted_fate' => 'read', 'mearing_recut' => 1], 'walked', ['kessa', 'ilse'], ['raises her cup to the pencil', '"Fifteen."', 'already been moved']],
              [['costing_figure' => 'circulated', 'cartulary_deposited' => '1'], 'costed', ['aldric', 'sera'], ['what it was for', 'found it by now', 'laugh at anything to do with Arden']]] as [$fl, $fate, $cs, $needles]) {
        $p = party($db, $base + $fl); ended($db, $p, $fate);
        $c = new CompanionService($db); foreach ($cs as $k) { $c->meet($p, $k); $c->recruit($p, $k); }
        $seek($p, 'mara_hearthstone', 'Last packet that makes');
        $t = $play($p, 'mara_hearthstone');
        foreach ($needles as $nd) ok("eve aside: $nd", str_contains($t, $nd), substr($t, 0, 140));
        ok('no aside from a companion not in the party', !str_contains($t, '[' . (in_array('kessa', $cs) ? 'aldric' : 'kessa') . ']'));
        ok('the eve still leaves it undecided', str_contains($t, 'has not been decided yet'));
        // packet
        $ok = $seek($p, 'osgar_tull', 'makes the quarter-day');
        ok('packet at first light after the eve', $ok);
        $t = $play($p, 'osgar_tull', 4);
        ok('the packet goes and nothing is said yet', str_contains($t, 'have not said it yet') && flag($db, $p, 'packet_gone') === '1', substr($t, 0, 120));
        if (($fl['vote_fate'] ?? '') === 'proxy') ok('Reed goes aboard with the proxy', str_contains($t, 'strongbox'));
        ok('the packet goes once', !$seek($p, 'osgar_tull', 'makes the quarter-day'));
    }
    $p = party($db, $base + ['vote_fate' => 'kept', 'quarter_eve' => 1]); ended($db, $p, 'subscribed');
    ok('a kept vote is taken to the plank, not past it', $seek($p, 'osgar_tull', 'I go at the bell') && flag($db, $p, 'packet_gone') === null);
    $p = party($db, $base + ['vote_fate' => 'against']); ended($db, $p, 'subscribed');
    ok('no packet before the eve', !$seek($p, 'osgar_tull', 'makes the quarter-day'));

    echo "== more of the valley is heard of ==\n";
    foreach ([['mill_is_shelter', 'street_drover', 'Dry wall'], ['rivermark_mustering', 'serjeant_ballow', 'Muster list'],
              ['stilt_camp_taken', 'osric_apothecary', 'across their planks'], ['stilt_camp_lost', 'captain_elowen', 'pulled the planks']] as [$fl, $npc, $needle]) {
        $p = party($db, $base + [$fl => 1]); ended($db, $p, 'walked');
        ok("$npc reads $fl", $seek($p, $npc, $needle));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("once ($fl)", !$seek($p, $npc, $needle));
    }
    echo "== the four days ==\n";
    $placed = function (int $p, string $npc) use ($db): bool {
        $w = $db->query("SELECT place_conditions_json FROM npcs WHERE npc_key=" . $db->quote($npc))->fetchColumn();
        $c = (new WorldState($db))->context($p, (int) $db->query("SELECT character_id FROM character_party WHERE party_id=$p LIMIT 1")->fetchColumn());
        return $w === null || $w === false || Requirements::pass(json_decode((string) $w, true), $c);
    };
    foreach ([['mara_hearthstone', 'facing east'], ['tibald_ashe', 'not given it one'], ['alder_pyke', 'already know the length'],
              ['gest_pyle', 'comes up on a boat'], ['osric_apothecary', 'chamomile'], ['brenna_smith', 'hinges for a door'],
              ['serjeant_ballow', 'looking the other way']] as [$npc, $needle]) {
        $p = party($db, $base + ['vote_fate' => 'against', 'quarter_eve' => 1, 'packet_gone' => 1]); ended($db, $p, 'subscribed');
        ok("waiting: $npc", $seek($p, $npc, $needle));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("waiting once: $npc", !$seek($p, $npc, $needle));
        $p = party($db, $base + ['vote_fate' => 'against', 'quarter_eve' => 1]); ended($db, $p, 'subscribed');
        ok("not before the packet goes: $npc", !$seek($p, $npc, $needle));
    }
    $waitAll = ['facing east', 'not given it one', 'already know the length', 'comes up on a boat', 'chamomile', 'hinges for a door', 'looking the other way', 'Captain\'s downriver', 'You stayed'];
    $p = party($db, $base + ['vote_fate' => 'proxy', 'quarter_eve' => 1, 'packet_gone' => 1]); ended($db, $p, 'subscribed');
    ok('Ballow minds the watch house while Reed is downriver', $seek($p, 'serjeant_ballow', "Captain's downriver"));
    ok('Reed is not on the square while she is downriver', !$placed($p, 'captain_elowen'));
    (new Effects($db))->apply($p, [['start_quest' => 'the_watch_payroll']]);
    ok('unless a quest of hers is still open', qstatus($db, $p, 'the_watch_payroll') === 'active' && $placed($p, 'captain_elowen'), (string) qstatus($db, $p, 'the_watch_payroll'));
    $p = party($db, $base + ['vote_fate' => 'against', 'packet_gone' => 1]); ended($db, $p, 'subscribed');
    ok('Reed stays for any other vote', $placed($p, 'captain_elowen'));
    $p = party($db, $base + ['costing_figure' => 'read_first', 'clearance_costed' => 1, 'packet_gone' => 1]); ended($db, $p, 'costed');
    ok('Hesper went down to read it first', !$placed($p, 'hesper_amsel'));
    $p = party($db, $base + ['costing_figure' => 'read_first', 'clearance_costed' => 1]); ended($db, $p, 'costed');
    ok('Hesper is on Granary Row until the packet goes', $placed($p, 'hesper_amsel'));
    $p = party($db, $base + ['costing_figure' => 'circulated', 'clearance_costed' => 1, 'packet_gone' => 1]); ended($db, $p, 'costed');
    ok('Hesper stays if she did not go', $placed($p, 'hesper_amsel'));

    echo "== the kept vote at the gangplank ==\n";
    $gang = function (array $cs) use ($db, $base, $seek) {
        $p = party($db, $base + ['vote_fate' => 'kept', 'quarter_eve' => 1]); ended($db, $p, 'subscribed');
        $c = new CompanionService($db); foreach ($cs as $k) { $c->meet($p, $k); $c->recruit($p, $k); }
        $seek($p, 'osgar_tull', 'I go at the bell');
        return $p;
    };
    $p = $gang(['sera', 'kessa']);
    $n = talk($db, $p, 'osgar_tull', ['Walk down to the plank']);
    $ij = implode(' ', array_map(fn ($i) => $i['text'], $n['interjections'] ?? []));
    ok('the plank: the bell has not gone', str_contains((string) $n['text'], 'The bell has not gone yet'));
    ok('the plank: no choice is made for you', flag($db, $p, 'packet_gone') === null && flag($db, $p, 'vote_aboard') === null);
    ok('the plank: Sera and Kessa, not Aldric', str_contains($ij, "I'm not going to tell you which") && str_contains($ij, 'somebody in that room') && !str_contains($ij, 'takes his hat off'), $ij);
    $labels = array_map(fn ($c) => $c['label'], $n['choices'] ?? []);
    ok('the plank offers both', in_array('Step aboard.', $labels) && in_array('Step back onto the stair.', $labels));
    $n = talk($db, $p, 'osgar_tull', ['Walk down to the plank', 'Step back']);
    ok('stepping back leaves the vote uncast', str_contains((string) ($n['text'] ?? ''), 'uncast') && flag($db, $p, 'packet_gone') === '1' && flag($db, $p, 'vote_aboard') === null);
    ok('…and nothing about the vote is set', flag($db, $p, 'vote_fate') === 'kept' && qstatus($db, $p, 'one_vote_in_twelve') !== 'completed');
    ok('Sera on the stair', $seek($p, 'sera', 'kept it there'));
    ok('Reed: you stayed', $seek($p, 'captain_elowen', 'You stayed'));
    ok('the town waits for a kept vote too', $seek($p, 'mara_hearthstone', 'facing east'));
    ok('Tull goes once', !$seek($p, 'osgar_tull', 'I go at the bell'));

    $p = $gang(['aldric']);
    talk($db, $p, 'osgar_tull', ['Walk down to the plank', 'Step aboard', 'Go below']);
    ok('aboard: the packet goes with you', flag($db, $p, 'vote_aboard') === '1' && flag($db, $p, 'packet_gone') === '1' && here($db, $p) === 'brackwater_wharf', here($db, $p));
    ok('aboard: the vote is still yours', flag($db, $p, 'vote_fate') === 'kept');
    ok('Moss writes it in', $seek($p, 'abel_moss', 'Good luck'));
    ok('Minna keeps the chair and asks nothing', $seek($p, 'minna_crale', "I'll not ask how you mean to vote"));
    talk($db, $p, 'minna_crale', ['Leave']);
    ok('Minna says it once', !$seek($p, 'minna_crale', "I'll not ask how you mean to vote"));
    foreach ($waitAll as $nd) {
        foreach (['mara_hearthstone', 'serjeant_ballow', 'captain_elowen'] as $npc) if ($seek($p, $npc, $nd)) { ok("no waiting line aboard ($npc: $nd)", false); }
    }
    ok('nobody in Rivermark waits for you when you went', true);

    echo "== still more of the valley ==\n";
    foreach ([['mill_pit_cleared', 'wick_lorrimer', 'only water'], ['root_beds_cleared', 'nesh_gray', 'we cut it'],
              ['ford_record', 'prioress_hale', 'fourth winter has been read'], ['commons_question_raised', 'street_drover', 'weighing floor'],
              ['pit_closed', 'vessa_dann', 'did the arithmetic'], ['broken_floor_shored', 'nakka', 'gallery holds']] as [$fl, $npc, $needle]) {
        $p = party($db, $base + [$fl => 1]); ended($db, $p, 'walked');
        ok("$npc reads $fl", $seek($p, $npc, $needle));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("once ($fl)", !$seek($p, $npc, $needle));
    }
    $p = party($db, $base + ['root_beds_cleared' => 1, 'wardens_fate' => 'turned']); ended($db, $p, 'walked');
    ok('no beds line when the camp is gone', !$seek($p, 'nesh_gray', 'we cut it'));
    echo "== the room sat ==\n";
    $back = function (array $fl, string $fate, array $cs = []) use ($db, $base, $seek) {
        $p = party($db, $base + $fl + ['quarter_eve' => 1, 'packet_gone' => 1]); ended($db, $p, $fate);
        $c = new CompanionService($db); foreach ($cs as $k) { $c->meet($p, $k); $c->recruit($p, $k); }
        return $p;
    };
    $p = $back(['vote_fate' => 'against'], 'subscribed', ['sera', 'kessa']);
    ok('no packet back before the town has waited', !$seek($p, 'osgar_tull', 'comes round the second bend'));
    $seek($p, 'mara_hearthstone', 'facing east'); talk($db, $p, 'mara_hearthstone', ['Leave']);
    ok('waiting marks the days', flag($db, $p, 'days_waited') === '1');
    ok('the packet comes back', $seek($p, 'osgar_tull', 'comes round the second bend'));
    $n = talk($db, $p, 'osgar_tull', ['Read the sheet']);
    $t = (string) $n['text']; $ij = implode(' ', array_map(fn ($i) => $i['text'], $n['interjections'] ?? []));
    ok('the sheet: the house sat', str_contains($t, 'The house sat on the quarter-day') && str_contains($t, 'The minute will follow'));
    foreach (['carried', 'passed', 'defeated', 'resolved', 'majority', 'against', 'in favour', 'adopted', 'rejected'] as $w)
        if (stripos($t . ' ' . $ij, $w) !== false) ok("the sheet says nothing of the outcome ($w)", false);
    ok('the sheet says nothing of the outcome', true);
    ok('Sera and Kessa on the sheet', str_contains($ij, 'Taken in its order') && str_contains($ij, 'had a meeting'), $ij);
    talk($db, $p, 'osgar_tull', ['Read the sheet', 'Go up']);
    ok('room_sat, vote still as it was', flag($db, $p, 'room_sat') === '1' && flag($db, $p, 'vote_fate') === 'against');
    ok('the packet comes back once', !$seek($p, 'osgar_tull', 'comes round the second bend'));
    foreach ([['mara_hearthstone', 'some people sat in chairs'], ['tibald_ashe', 'The house sat.'], ['alder_pyke', 'what the room did'], ['serjeant_ballow', 'in its order']] as [$npc, $nd]) {
        ok("after: $npc", $seek($p, $npc, $nd));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("after once: $npc", !$seek($p, $npc, $nd));
    }
    $p = $back(['vote_fate' => 'proxy'], 'subscribed', ['aldric']);
    $seek($p, 'serjeant_ballow', "Captain's downriver"); $n = (new DialogEngine($db))->node($p, 'serjeant_ballow'); talk($db, $p, 'serjeant_ballow', [(string) end($n['choices'])['label']]);
    ok('Reed away while the packet is', !$placed($p, 'captain_elowen'));
    talk($db, $p, 'osgar_tull', ['Let somebody else', 'Go up']);
    ok('Reed comes home with it', $placed($p, 'captain_elowen'));
    ok('Reed cast it as given and will not say', $seek($p, 'captain_elowen', "I'll not tell you what the room did"));
    ok('Aldric on chapter minutes', $seek($p, 'aldric', 'chapter minutes'));
    $p = $back(['costing_figure' => 'read_first', 'clearance_costed' => 1], 'costed');
    $seek($p, 'mara_hearthstone', 'facing east'); talk($db, $p, 'mara_hearthstone', ['Leave']);
    ok('Hesper away', !$placed($p, 'hesper_amsel'));
    talk($db, $p, 'osgar_tull', ['Read the sheet', 'Go up']);
    ok('Hesper back on the row', $placed($p, 'hesper_amsel') && $seek($p, 'hesper_amsel', 'different column'));
    $p = party($db, $base + ['vote_fate' => 'kept', 'quarter_eve' => 1, 'packet_gone' => 1, 'vote_aboard' => 1, 'days_waited' => 1]); ended($db, $p, 'subscribed');
    ok('no sheet from the stair if you went down with it', !$seek($p, 'osgar_tull', 'comes round the second bend'));

    echo "== the far corners ==\n";
    foreach ([['drowned_nave_clear', 'cully_tench', 'fourth step from the punt'], ['black_pools_cleared', 'cully_tench', 'near cuttings'],
              ['pump_gallery_cleared', 'corrin_dale', 'left them once'], ['pit_gate_beaten', 'vessa_dann', 'already moving'],
              ['oldcity_goblins_broken', 'bram_ockley', 'foothold'], ['fen_water_rising', 'tull_barrow', 'wrong side'],
              ['rivermark_valued', 'tibald_ashe', 'less than the crane']] as [$fl, $npc, $needle]) {
        $p = party($db, $base + [$fl => 1]); ended($db, $p, 'walked');
        ok("$npc reads $fl", $seek($p, $npc, $needle));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("once ($fl)", !$seek($p, $npc, $needle));
    }
    echo "== between the sheet and the minute ==\n";
    foreach ([['osgar_tull', 'Not on this boat'], ['dorcas_fenn', 'Quarter-day'], ['brenna_smith', 'I made the hinge'], ['osric_apothecary', 'headaches'],
              ['wenna_coyle', 'changes the pegs'], ['aggie_slate', 'nor has anybody'], ['mara_hearthstone', 'some people sat in chairs']] as [$npc, $nd]) {
        $p = party($db, $base + ['vote_fate' => 'against', 'quarter_eve' => 1, 'packet_gone' => 1, 'days_waited' => 1, 'room_sat' => 1]); ended($db, $p, 'subscribed');
        ok("between: $npc", $seek($p, $npc, $nd));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("between once: $npc", !$seek($p, $npc, $nd));
        $p = party($db, $base + ['vote_fate' => 'against', 'quarter_eve' => 1, 'packet_gone' => 1, 'days_waited' => 1]); ended($db, $p, 'subscribed');
        ok("not before the sheet: $npc", !$seek($p, $npc, $nd));
    }
    $p = party($db, $base + ['vote_fate' => 'kept', 'vote_aboard' => 1, 'packet_gone' => 1, 'room_sat' => 1]); ended($db, $p, 'subscribed');
    ok('none of it if you went aboard', !$seek($p, 'dorcas_fenn', 'Quarter-day') && !$seek($p, 'osgar_tull', 'Not on this boat'));
    foreach ([['sera', 'nine times'], ['kessa', 'opposite sides'], ['ilse', 'weather with a seal']] as [$c, $nd]) {
        $p = party($db, $base + ['vote_fate' => 'against', 'room_sat' => 1, 'packet_gone' => 1]); ended($db, $p, 'subscribed');
        $cs = new CompanionService($db); $cs->meet($p, $c); $cs->recruit($p, $c);
        ok("$c between", $seek($p, $c, $nd));
    }
    echo "== what the valley knows ==\n";
    foreach ([['fen_road_known', 'gest_pyle', 'a stroke each'], ['grazing_bought_known', 'alder_pyke', 'Winter Field'],
              ['warren_numbers_known', 'alder_pyke', 'stand on'], ['mill_race_right_known', 'wick_lorrimer', 'dry August'],
              ['ford_huts_known', 'mara_hearthstone', 'asking at the ford'], ['warren_nests_known_to_watch', 'serjeant_ballow', 'having it']] as [$fl, $npc, $needle]) {
        // Mara holds a walked-out party on item seven until it is adjourned, so read her after a costing.
        $p = party($db, $base + [$fl => 1]); ended($db, $p, $npc === 'mara_hearthstone' ? 'costed' : 'walked');
        ok("$npc reads $fl", $seek($p, $npc, $needle));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("once ($fl)", !$seek($p, $npc, $needle));
    }
    echo "== people remember you ==\n";
    foreach (['dorn_ackley' => ['met_ackley', 'still not two'], 'tull_barrow' => ['met_barrow', "Fire's still mine"], 'wenna_coyle' => ['met_coyle', 'Still no peg number'],
              'cully_tench' => ['met_cully', 'a copper and a reason?'], 'corrin_dale' => ['met_dale', 'Still not on a face'], 'joss_frayle' => ['met_frayle', 'private thing'],
              'nesh_gray' => ['met_gray', 'planks stay down'], 'prioress_hale' => ['met_hale', 'not changed the arrangement'], 'sten_harrow' => ['met_harrow', "Road's still east"],
              'hesk_dray' => ['met_hesk', 'Wagons are still mine'], 'orm_lisser' => ['met_orm', 'Back through the gate'], 'sister_ovid' => ['met_ovid', 'she knows you'],
              'gillian_petch' => ['met_petch', 'Still lost, or still the crane'], 'alder_pyke' => ['met_pyke', 'Still a shilling'], 'bael_rourke' => ['met_rourke', "I'd remember"],
              'brother_teodor' => ['met_teodor', 'Properly, then'], 'osgar_tull' => ['met_tull', 'Same as when you asked'], 'almoner_venn' => ['met_venn', 'nods without turning'],
              'vessa_dann' => ['met_vessa', 'changes the number'], 'wick_lorrimer' => ['met_wick', 'from him is hello'], 'gubb_wrayne' => ['met_wrayne', 'Still up']] as $npc => [$fl, $nd]) {
        $p = party($db, [$fl => 1]);
        $g = greet($db, $p, $npc);
        ok("$npc remembers you", str_contains($g, $nd), substr($g, 0, 80));
        $n = (new DialogEngine($db))->node($p, $npc);
        ok("$npc: same replies as before", count($n['choices'] ?? []) >= 2);
        $p = party($db, []);
        ok("$npc: a stranger still gets the first meeting", !str_contains(greet($db, $p, $npc), $nd));
    }
    echo "== what Corse said travels ==\n";
    foreach ([['corse_said_the_narrow_part', 'alder_pyke', 'forty miles round', []], ['corse_told_the_area', 'gest_pyle', 'Most of the valley', []],
              ['corse_named_the_works', 'corrin_dale', 'not the comfort', []], ['corse_explained_clearance', 'mara_hearthstone', 'A head in a column', []],
              ['knows_corse_name', 'alder_pyke', 'for his paper', []], ['corse_declined_one', 'kessa', 'three streets later', ['kessa']],
              ['corse_said_for', 'aldric', 'decides to own a valley', ['aldric']]] as [$fl, $npc, $nd, $cs]) {
        $p = party($db, $base + [$fl => 1]); ended($db, $p, 'costed');
        $c = new CompanionService($db); foreach ($cs as $k) { $c->meet($p, $k); $c->recruit($p, $k); }
        ok("$npc hears $fl", $seek($p, $npc, $nd));
        if (!$cs) { $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]); ok("once ($fl)", !$seek($p, $npc, $nd)); }
    }
    $p = party($db, $base + ['knows_corse_name' => 1, 'met_corse' => 1]); ended($db, $p, 'costed');
    ok('Pyke does not wonder about a man you have met', !$seek($p, 'alder_pyke', 'for his paper'));
    echo "== in its order ==\n";
    $sat = $base + ['vote_fate' => 'against', 'quarter_eve' => 1, 'packet_gone' => 1, 'days_waited' => 1, 'room_sat' => 1];
    $p = party($db, $base + ['vote_fate' => 'against', 'packet_gone' => 1]); ended($db, $p, 'subscribed');
    ok('Dale does not ask before the sheet', !$seek($p, 'corrin_dale', 'come up the river'));
    $runs = [];
    foreach (['Read it word for word' => ['read', 'Again', 'aldric'], 'Tell them what you think' => ['guessed', 'your voice on it', 'sera'], "minute's not here yet" => ['not_yet', 'honest one', 'sera']] as $label => [$fate, $nd, $c]) {
        $p = party($db, $sat); ended($db, $p, 'subscribed');
        $cs = new CompanionService($db); $cs->meet($p, $c); $cs->recruit($p, $c);
        ok("Dale asks for the sheet ($fate)", $seek($p, 'corrin_dale', 'come up the river'));
        talk($db, $p, 'corrin_dale', ['bring a copy']);
        ok("started ($fate)", qstatus($db, $p, 'in_its_order') === 'active');
        ok("Ashe copies it ($fate)", str_contains(greet($db, $p, 'tibald_ashe'), 'best hand'));
        talk($db, $p, 'tibald_ashe', ['Take it down']);
        ok("the canteen stops eating ($fate)", str_contains(greet($db, $p, 'corrin_dale'), 'sixty men have stopped eating'));
        $n = talk($db, $p, 'corrin_dale', [$label]);
        $t = (string) ($n['text'] ?? ''); $ij = implode(' ', array_map(fn ($i) => $i['text'], $n['interjections'] ?? []));
        ok("$fate: $nd", str_contains($t, $nd), substr($t, 0, 100));
        ok("$fate: a companion says so", $ij !== '', $ij);
        ok("$fate: done, vote untouched", flag($db, $p, 'order_fate') === $fate && qstatus($db, $p, 'in_its_order') === 'completed' && flag($db, $p, 'vote_fate') === 'against');
        foreach (['carried', 'passed', 'defeated', 'majority', 'in favour', 'rejected', 'adopted'] as $w) if (stripos($t, $w) !== false) ok("no outcome in the canteen ($w)", false);
        $runs[$fate] = $p;
    }
    ok('Rourke hears the guess', $seek($runs['guessed'], 'bael_rourke', 'your voice'));
    ok('Rourke hears the words', $seek($runs['read'], 'bael_rourke', 'down the shaft'));
    ok('Coyle writes it in', $seek($runs['not_yet'], 'wenna_coyle', 'a reading'));
    $p = party($db, $sat); ended($db, $p, 'subscribed');
    talk($db, $p, 'corrin_dale', []); $seek($p, 'corrin_dale', 'come up the river'); talk($db, $p, 'corrin_dale', ['Not today']);
    ok('the offer waits if you say not today', $seek($p, 'corrin_dale', 'come up the river'));
    foreach ([['ludo_corse', 'same boat'], ['gillian_petch', "crane hasn't stopped"], ['gest_pyle', "minute's come"]] as [$npc, $nd]) {
        $p = party($db, $sat); ended($db, $p, 'subscribed');
        ok("after the sheet: $npc", $seek($p, $npc, $nd));
        $n = (new DialogEngine($db))->node($p, $npc); talk($db, $p, $npc, [(string) end($n['choices'])['label']]);
        ok("once: $npc", !$seek($p, $npc, $nd));
    }
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
