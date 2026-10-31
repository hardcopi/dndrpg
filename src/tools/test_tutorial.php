<?php
/**
 * "First Night at the Flagon" — the optional tutorial quest.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_tutorial.php`.
 *
 * Covers the four things the quest is built on:
 *   - opt-in to done: Mara's offer, every lesson advancing on something done,
 *     and the reward;
 *   - skipping from a middle stage, which still pays and still gives the lantern;
 *   - a party that never takes it reaching Mara's ordinary greeting, with no
 *     tutorial loot on the cellar floor and no help with Search;
 *   - the search flag: a found hidden way is written as found_exit_<key>, and
 *     the Search lesson cannot be failed by the dice while the tutorial is on it.
 *
 * Builds its own parties and removes them. Safe against a database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass,$fail;
    if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n"; } }

$loc = fn (string $key): int => (int) $db->query("SELECT id FROM locations WHERE location_key='$key'")->fetchColumn();
$moduleId = (int) $db->query("SELECT id FROM modules WHERE module_key='rivermark'")->fetchColumn();
$room = $loc('flagon_common_room');
$cellar = $loc('flagon_cellar');

$parties = [];
/** A level-1 party of one, standing in the Flagon. Intelligence 1, so Search is not trivially passed. */
function newParty(string $tag): array {
    global $db, $parties, $moduleId, $room;
    $db->prepare('INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, ?)')
        ->execute([$moduleId, "__tutorial_$tag"]);
    $pid = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO characters (name, race, class, level, strength, dexterity, constitution,
            intelligence, wisdom, charisma, max_hp, current_hp, armor_class, speed,
            proficiency_bonus, current_location_id)
         VALUES (?, "Human", "Fighter", 1, 12,12,12,1,12,12, 20,5, 14, 30, 2, ?)'
    )->execute(["__tutorial_$tag", $room]);
    $cid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_party (party_id, character_id) VALUES (?, ?)')->execute([$pid, $cid]);
    $db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')->execute([$cid, $pid]);
    $parties[] = [$pid, $cid];
    return [$pid, $cid];
}

/** A fresh engine each time: its WorldState caches flags, and this test writes some behind its back. */
function dlg(): DialogEngine { return new DialogEngine(db()); }
$world = new WorldState($db);
$locEngine = new LocationEngine($db);

/** Mara's greeting, as the player sees it. */
function greet(string $npc = 'mara_hearthstone'): array {
    global $curParty;
    return dlg()->node($curParty, $npc);
}
function say(array $node): string { return trim(preg_replace('/\s+/', ' ', $node['text'])); }
function labels(array $node): array { return array_column($node['choices'], 'label'); }
/** Press the reply with this label on a node already drawn. */
function pick(array $node, string $label, string $npc = 'mara_hearthstone'): array {
    global $curParty;
    $i = array_search($label, labels($node), true);
    if ($i === false) {
        throw new RuntimeException("no reply '$label' among " . json_encode(labels($node)));
    }
    return dlg()->choose($curParty, $npc, $node['node_key'], (int) $i);
}
function quest(string $key): array {
    global $db, $curParty;
    $s = $db->prepare('SELECT pq.status, pq.resolution, s.stage_key FROM party_quests pq
        JOIN quests q ON q.id = pq.quest_id LEFT JOIN quest_stages s ON s.id = pq.current_stage_id
        WHERE pq.party_id = ? AND q.quest_key = ?');
    $s->execute([$curParty, $key]);
    return $s->fetch() ?: [];
}
function stageOf(string $key): ?string { return quest($key)['stage_key'] ?? null; }
function has(string $itemKey): bool {
    global $db, $curParty;
    $s = $db->prepare('SELECT COALESCE(SUM(ci.quantity),0) FROM character_inventory ci
        JOIN character_party cp ON cp.character_id = ci.character_id
        JOIN items i ON i.id = ci.item_id WHERE cp.party_id = ? AND i.item_key = ?');
    $s->execute([$curParty, $itemKey]);
    return (int) $s->fetchColumn() > 0;
}
function xpOf(int $cid): int { global $db; return (int) $db->query("SELECT experience FROM characters WHERE id=$cid")->fetchColumn(); }
function flag(string $k): ?string { global $world, $curParty; $world->forget($curParty); return $world->get($curParty, $k); }

try {
    // =====================================================================
    // Opt-in to done
    // =====================================================================
    echo "opt-in to done\n";
    [$curParty, $cid] = newParty('walk');
    $_SESSION['character_id'] = $cid;
    $xp0 = xpOf($cid);

    $g = greet();
    ok('a new party is offered the tutorial', str_contains(say($g), 'First time in Rivermark?'), say($g));
    ok('the offer carries both replies', labels($g) === ['Show me how things go here.', 'I know my way around.'], json_encode(labels($g)));
    $again = greet();
    ok('closing the offer unanswered keeps it', str_contains(say($again), 'First time in Rivermark?'));

    $r = pick($g, 'Show me how things go here.');
    ok('accepting starts first_night at `talk`', stageOf('first_night') === 'talk', json_encode(quest('first_night')));
    ok('and tutorial_fate says it was taken', flag('tutorial_fate') === 'taken');
    ok('and moves on into the rats job', ($r['node']['node_key'] ?? '') === 'fn_rats_offer');
    ok('cellar_rats is not started by merely accepting', quest('cellar_rats') === []);

    $offer = $r['node'];
    ok('the rats offer has the haggle, a plain yes, a skip and a not-yet',
        labels($offer) === ['Twenty-five, and the stew.', 'Twenty is fine.', "I've got it from here.", 'Not yet.'],
        json_encode(labels($offer)));
    $c = pick($offer, 'Twenty-five, and the stew.');
    ok('the haggle is a Persuasion check at DC 8',
        ($c['check']['dc'] ?? null) === 8 && stripos(json_encode($c['check']), 'persuasion') !== false, json_encode($c['check'] ?? $c));
    $c = dlg()->resolveCheck($curParty, (string) ($c['check']['id'] ?? $c['check']['check_id'] ?? ''), []);
    ok('either way the job is taken and the lesson moves on (' . ($c['roll']['outcome'] ?? '?') . ')',
        (quest('cellar_rats')['status'] ?? '') === 'active' && stageOf('first_night') === 'journal',
        json_encode([quest('cellar_rats'), quest('first_night')]));
    ok('and it says to write it down', str_contains(say($c['node']), 'journal'), say($c['node']));

    $g = greet();
    ok('the next Mara line is the errand', str_contains(say($g), 'Tobin') && str_contains(say($g), 'lantern'), say($g));
    ok('and the journal lesson is behind us', stageOf('first_night') === 'errand');
    ok('the lantern is not handed over by Mara', !has('tutorial_lantern'));

    $t = dlg()->node($curParty, 'tobin');
    ok('Tobin hands over the lantern', has('tutorial_lantern') && str_contains(say($t), "Mara's lantern"), say($t));
    ok('and the rats lesson begins', stageOf('first_night') === 'the_rats');

    $g = greet();
    ok('before the rats are dead Mara says nothing of the tutorial', str_contains(say($g), 'third step'), say($g));
    ok('and the quest has not moved', stageOf('first_night') === 'the_rats');

    $world->set($curParty, 'cellar_rats_beaten');
    $g = greet();
    ok('once the rats are beaten Mara sends them back for the tally', str_contains(say($g), 'tally stick'), say($g));
    ok('and the loot lesson begins', stageOf('first_night') === 'loot' && flag('tutorial_loot_open') === '1');
    ok('the ordinary rats reply is still one click away', in_array('About the rats.', labels($g), true));
    $back = pick($g, 'About the rats.')['node'];
    ok('which lands on the real conversation about the rats', in_array('Dead. All of them.', labels($back), true), json_encode(labels($back)));

    $db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$cellar, $cid]);
    $state = $locEngine->getState($cid, $curParty);
    $names = array_column($state['location']['items'] ?? [], 'name');
    ok('the tally lies on the cellar floor for a party on the lesson', $names === ["Mara's Cellar Tally"], json_encode($names));
    $lootId = (int) $state['location']['items'][0]['location_item_id'];
    $locEngine->lootItem($cid, $lootId);
    ok('and can be taken', has('tutorial_tally'));
    $db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$room, $cid]);

    $g = greet();
    ok('Mara takes the tally and sends them to look for the draught', str_contains(say($g), 'draught'), say($g));
    ok('the search lesson begins', stageOf('first_night') === 'search');
    ok('and the stick is hers now', !has('tutorial_tally'));

    $g = greet();
    ok('before the exit is found, the search lesson does not shadow Mara', !str_contains(say($g), 'for later'), say($g));
    $db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$cellar, $cid]);
    $misses = 0;
    for ($i = 0; $i < 25; $i++) {
        $db->prepare('DELETE FROM party_exits_found WHERE party_id = ?')->execute([$curParty]);
        $world->clear($curParty, 'found_exit_sump_drain');
        $res = $locEngine->search($cid);
        if ((int) $res['found_exits'] !== 1) { $misses++; }
    }
    ok('Search cannot be failed on the Search lesson (25 tries, intelligence 1)', $misses === 0, "$misses misses");
    ok('and the find is written as a party flag', flag('found_exit_sump_drain') === '1');
    $db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$room, $cid]);

    $g = greet();
    ok('Mara says the drain is for later', str_contains(say($g), 'for later'), say($g));
    ok('and the rest lesson begins', stageOf('first_night') === 'rest');

    $g = greet();
    ok('Mara offers the room, and the Camp button for the road',
        str_contains(say($g), 'on the house') && str_contains(say($g), 'Camp'), say($g));
    $r = pick($g, 'Thank you. I\'ll take the room.');
    $q = quest('first_night');
    ok('taking it finishes the quest', $q['status'] === 'completed' && $q['resolution'] === 'done', json_encode($q));
    ok('and the room was free and healed them',
        (int) $db->query("SELECT current_hp FROM characters WHERE id=$cid")->fetchColumn() === 20);
    ok('and pays the small reward', xpOf($cid) - $xp0 >= 50, 'xp gained ' . (xpOf($cid) - $xp0));
    ok('and the lantern is kept', has('tutorial_lantern'));
    ok('and tutorial_fate says done', flag('tutorial_fate') === 'done');
    ok('and the last line is Mara saying so', str_contains(say($r['node']), "That's the Flagon"), say($r['node']));

    // =====================================================================
    // Skipping from a middle stage
    // =====================================================================
    echo "skip from a middle stage\n";
    [$curParty, $cid] = newParty('skip');
    $_SESSION['character_id'] = $cid;
    $xp0 = xpOf($cid);
    pick(greet(), 'Show me how things go here.');
    $offer = dlg()->node($curParty, 'mara_hearthstone', 'fn_rats_offer');
    pick($offer, 'Twenty is fine.');
    ok('took the job', stageOf('first_night') === 'journal');
    $g = greet();           // the errand line; the stage is now `errand`
    ok('standing at the errand', stageOf('first_night') === 'errand');
    $r = pick($g, "I've got it from here.");
    $q = quest('first_night');
    ok('skipping from the middle ends the quest as skipped', $q['status'] === 'completed' && $q['resolution'] === 'skipped', json_encode($q));
    ok('and still pays the same XP', xpOf($cid) - $xp0 >= 50);
    ok('and still gives the lantern', has('tutorial_lantern'));
    ok('and tutorial_fate says skipped', flag('tutorial_fate') === 'skipped');
    ok('cellar_rats is untouched by skipping', quest('cellar_rats')['status'] === 'active' && quest('cellar_rats')['stage_key'] === 'into_the_cellar');
    $g = greet();
    ok('Mara is back to her ordinary greeting', str_contains(say($g), 'Still down there'), say($g));
    ok('and the tally stays off the cellar floor', (function () use ($db, $locEngine, $cid, $cellar, $curParty) {
        $db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$cellar, $cid]);
        return array_column($locEngine->getState($cid, $curParty)['location']['items'] ?? [], 'name') === [];
    })());

    // =====================================================================
    // A party that says no
    // =====================================================================
    echo "a skipper reaching Mara's normal greeting\n";
    [$curParty, $cid] = newParty('no');
    $_SESSION['character_id'] = $cid;
    $r = pick(greet(), 'I know my way around.');
    ok('declining goes straight on to her ordinary greeting', str_contains(say($r['node']), 'Welcome to the Golden Flagon'), say($r['node']));
    ok('with her usual replies', in_array('What problem?', labels($r['node']), true) && in_array('A room, please.', labels($r['node']), true));
    ok('no tutorial quest row exists', quest('first_night') === []);
    ok('tutorial_fate says skipped', flag('tutorial_fate') === 'skipped');
    $g = greet();
    ok('and the offer never comes back', !str_contains(say($g), 'First time in Rivermark?'), say($g));
    $terms = pick($g, 'What problem?')['node'];
    pick($terms, "I'll clear it out.");
    ok('the rats job is still Mara\'s ordinary one', quest('cellar_rats')['status'] === 'active' && quest('first_night') === []);
    $g = greet();
    ok('and she greets the rat-catcher as she always did', str_contains(say($g), 'Still down there'), say($g));

    // =====================================================================
    // The search flag, without the tutorial
    // =====================================================================
    echo "the search flag\n";
    $db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?')->execute([$cellar, $cid]);
    $misses = 0; $finds = 0; $mismatch = 0;
    for ($i = 0; $i < 40; $i++) {
        $db->prepare('DELETE FROM party_exits_found WHERE party_id = ?')->execute([$curParty]);
        $world->clear($curParty, 'found_exit_sump_drain');
        $res = $locEngine->search($cid);
        $found = (int) $res['found_exits'] === 1;
        $flagged = flag('found_exit_sump_drain') === '1';
        if ($found !== $flagged) {
            $mismatch++;
        }
        $found ? $finds++ : $misses++;
    }
    ok('the flag is written exactly when the exit is found (40 tries)', $mismatch === 0, "$mismatch mismatches");
    ok('an ordinary search still fails sometimes (intelligence 1, DC 13): ' . "$misses misses, $finds finds", $misses > 0);
} finally {
    foreach ($parties as [$pid, $cid]) {
        foreach (['party_quest_stages','party_quests','world_flags','party_exits_found','party_items_taken'] as $t) {
            $db->prepare("DELETE FROM $t WHERE party_id=?")->execute([$pid]);
        }
        $db->prepare('DELETE FROM character_inventory WHERE character_id=?')->execute([$cid]);
        $db->prepare('DELETE FROM character_party WHERE party_id=?')->execute([$pid]);
        $db->prepare('UPDATE parties SET leader_character_id = NULL WHERE id=?')->execute([$pid]);
        $db->prepare('DELETE FROM characters WHERE id=?')->execute([$cid]);
        $db->prepare('DELETE FROM parties WHERE id=?')->execute([$pid]);
    }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
