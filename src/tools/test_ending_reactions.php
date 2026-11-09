<?php
/**
 * The people an ending happened to say so the next time you speak to them.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_ending_reactions.php`.
 *
 * For each ending wired on 9 Nov 2026 (Nakka's three quests, Reed, the
 * freeplay watch house, Jimmy Able, Osric Fen and Fen-Root) it builds a fresh
 * party, finishes the quest at that ending through QuestService — so the
 * terminal stage's own effects write the flags — and checks the first thing
 * the NPC says is the line written for it. Also that Osric offers Fen-Root.
 * Builds its own parties and removes them.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass,$fail;
  if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n".($d?"\n         $d":'')."\n"; } }
$made = [];
function party(PDO $db, string $module = 'rivermark'): int {
    global $made;
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
        (SELECT id FROM modules WHERE module_key=?), 'reaction probe')")->execute([$module]);
    $pid = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
            constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
            proficiency_bonus, is_active, gold)
        VALUES (?, 'Human', 'Fighter', 6, 14000, 14, 12, 14, 12, 12, 12, 50, 50, 16, 3, 1, 500)")
        ->execute(['_reaction_probe_' . bin2hex(random_bytes(3))]);
    $cid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,1)')->execute([$pid, $cid]);
    $db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')->execute([$cid, $pid]);
    $made[] = [$pid, $cid];
    return $pid;
}
// [module, quest, terminal stage, npc, words the reaction must contain]
$cases = [
    ['rivermark', 'nakkas_plate', 'she_took_it_off', 'nakka', 'Plumb-line and a square'],
    ['rivermark', 'nakkas_plate', 'traced_the_crate', 'nakka', "isn't yours"],
    ['rivermark', 'nakkas_plate', 'let_it_be', 'nakka', 'Most people from the valley ask'],
    ['rivermark', 'the_bottom_of_the_warren', 'sounded_the_floor', 'nakka', 'Swept'],
    ['rivermark', 'the_bottom_of_the_warren', 'shored_it', 'nakka', 'Winter'],
    ['rivermark', 'the_bottom_of_the_warren', 'let_it_go', 'nakka', 'square edges'],
    ['rivermark', 'the_warrens_children', 'moved_them_north', 'nakka', 'A hundred and ten'],
    ['rivermark', 'the_warrens_children', 'fed_them_through', 'nakka', 'feeding a nursery'],
    ['rivermark', 'the_warrens_children', 'told_the_watch', 'nakka', 'Remember that I know'],
    ['rivermark', 'the_warrens_children', 'told_the_watch', 'captain_elowen', 'The galleries'],
    ['rivermark', 'the_warrens_children', 'moved_them_north', 'captain_elowen', 'north ford'],
    ['old_city', 'jimmy_the_ninth_man', 'made_him_say_it', 'jimmy_able', 'Seven'],
    ['old_city', 'jimmy_the_ninth_man', 'kept_it_quiet', 'jimmy_able', 'Still nine'],
    ['old_city', 'jimmy_the_ninth_man', 'told_them_myself', 'jimmy_able', 'one of the eight'],
    ['rivermark', 'fen_root', 'cut_it_yourself', 'osric_apothecary', 'Cut well'],
    ['rivermark', 'fen_root', 'paid_the_toll', 'osric_apothecary', 'Eighteen'],
    ['rivermark', 'fen_root', 'bought_the_season', 'osric_apothecary', 'A supply'],
    ['freeplay', 'fp_watch_cousin', 'returned', '_fp_brenna', "not his words"],
    ['freeplay', 'fp_watch_crate', 'returned', '_fp_odd', 'pewter sun-disc'],
    ['freeplay', 'fp_watch_wine', 'returned', '_fp_odd', 'green bottle'],
    ['freeplay', 'fp_watch_name', 'returned', '_fp_hessa', 'Calder'],
    ['freeplay', 'fp_watch_shrine', 'returned', '_fp_hessa', 'Slept through'],
];
try {
    $mods = $db->query("SELECT module_key FROM modules")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cases as [$mod, $q, $stage, $npc, $words]) {
        if (!in_array($mod, $mods, true)) {
            // the freeplay module key differs between installs; fall back to the quest giver's module
            $mod = (string) $db->query("SELECT m.module_key FROM quests q JOIN npcs n ON n.id=q.giver_npc_id
                JOIN locations l ON l.id=n.location_id JOIN regions r ON r.id=l.region_id
                JOIN modules m ON m.id=r.module_id WHERE q.quest_key=" . $db->quote($q))->fetchColumn();
        }
        $p = party($db, $mod);
        $qs = new QuestService($db, new Effects($db));
        $qs->start($p, $q);
        $qs->advance($p, $q, $stage);
        $n = (new DialogEngine($db))->node($p, $npc);
        ok("$npc notices $q/$stage", str_contains((string) $n['text'], $words), substr((string) $n['text'], 0, 110));
    }
    $p = party($db);
    $n = (new DialogEngine($db))->node($p, 'osric_apothecary');
    $labels = implode('|', array_map(fn ($c) => $c['label'], $n['choices'] ?? []));
    ok('Osric offers Fen-Root', str_contains($labels, 'fen-root'), $labels);
} catch (Throwable $e) {
    ok('ran without an error', false, $e->getMessage());
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
