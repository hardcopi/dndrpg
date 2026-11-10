<?php
/**
 * The database side of test_act_two_play.py, which plays Act II over HTTP.
 *
 *   php tools/act_two_play_seed.php seed <character_id> '<json>'   Act I state the HTTP walk cannot reach in seconds
 *   php tools/act_two_play_seed.php flags <party_id>               the party's world flags, as JSON
 *   php tools/act_two_play_seed.php locations                      location_key => id, as JSON
 *   php tools/act_two_play_seed.php parley <character_id> <npc:node>  a finished fight that ended in a parley, where they stand
 *   php tools/act_two_play_seed.php cleanup <username>             remove the throwaway account and everything it made
 *
 * Only `seed` writes game state, and only what Act I would have left behind:
 * level, gold, companions, a few flags and finished quests, and where the party
 * stands. Everything from the Survey onwards is played through the real routes.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$cmd = $argv[1] ?? '';

if ($cmd === 'locations') {
    echo json_encode($db->query('SELECT location_key, id FROM locations')->fetchAll(PDO::FETCH_KEY_PAIR)), "\n";
    exit(0);
}
if ($cmd === 'flags') {
    $st = $db->prepare('SELECT flag_key, flag_value FROM world_flags WHERE party_id = ?');
    $st->execute([(int) $argv[2]]);
    echo json_encode($st->fetchAll(PDO::FETCH_KEY_PAIR) ?: new stdClass()), "\n";
    exit(0);
}
if ($cmd === 'parley') {
    // What a fight that was talked down leaves behind: an inactive session whose
    // state says parley. The fight itself is not what is under test.
    $cid = (int) $argv[2];
    $spec = (string) $argv[3];
    $pid = (int) $db->query("SELECT party_id FROM character_party WHERE character_id = $cid LIMIT 1")->fetchColumn();
    $enc = (int) $db->query("SELECT id FROM encounters WHERE parley_node = " . $db->quote($spec) . " LIMIT 1")->fetchColumn();
    $db->prepare('INSERT INTO combat_sessions (character_id, location_id, encounter_id, party_id, current_turn, state_json, is_active)
                  SELECT id, current_location_id, ?, ?, 0, ?, 0 FROM characters WHERE id = ?')
       ->execute([$enc, $pid, json_encode(['status' => 'parley', 'parley_node' => $spec]), $cid]);
    echo json_encode(['ok' => $enc > 0]), "\n";
    exit(0);
}
if ($cmd === 'cleanup') {
    $uid = $db->prepare('SELECT id FROM users WHERE username = ?');
    $uid->execute([(string) $argv[2]]);
    $uid = (int) $uid->fetchColumn();
    if ($uid <= 0 || !str_starts_with((string) $argv[2], 'a2play_')) { exit(0); }
    $parties = $db->query("SELECT id FROM parties WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN);
    $chars = $db->query("SELECT id FROM characters WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($parties as $pid) {
        $pid = (int) $pid;
        foreach ($db->query("SELECT character_id FROM party_companions WHERE party_id = $pid")->fetchAll(PDO::FETCH_COLUMN) as $c) { $chars[] = $c; }
        foreach (['party_quest_stages', 'party_quests', 'world_flags', 'party_companions', 'party_encounters_cleared', 'party_exits_found',
                  'party_items_taken', 'party_locations_visited', 'dungeon_delves', 'combat_sessions', 'roll_log', 'character_party'] as $t) {
            try { $db->exec("DELETE FROM $t WHERE party_id = $pid"); } catch (Throwable $e) { fwrite(STDERR, "$t: {$e->getMessage()}\n"); }
        }
    }
    foreach (array_unique(array_map('intval', $chars)) as $cid) {
        foreach (['character_inventory', 'character_known_npcs', 'character_spells', 'pending_level_ups', 'combat_sessions', 'roll_log', 'character_party'] as $t) {
            try { $db->exec("DELETE FROM $t WHERE character_id = $cid"); } catch (Throwable $e) { fwrite(STDERR, "$t: {$e->getMessage()}\n"); }
        }
    }
    try { $db->exec("UPDATE parties SET leader_character_id = NULL WHERE user_id = $uid"); } catch (Throwable $e) {}
    foreach (array_unique(array_map('intval', $chars)) as $cid) {
        try { $db->exec("DELETE FROM characters WHERE id = $cid"); } catch (Throwable $e) { fwrite(STDERR, "character $cid: {$e->getMessage()}\n"); }
    }
    foreach (["DELETE FROM parties WHERE user_id = $uid", "DELETE FROM api_tokens WHERE user_id = $uid", "DELETE FROM users WHERE id = $uid"] as $q) {
        try { $db->exec($q); } catch (Throwable $e) { fwrite(STDERR, "{$e->getMessage()}\n"); }
    }
    exit(0);
}
if ($cmd !== 'seed') { fwrite(STDERR, "usage: seed|flags|locations|cleanup\n"); exit(2); }

$cid = (int) $argv[2];
$ops = json_decode($argv[3] ?? '{}', true) ?: [];
$pid = (int) $db->query("SELECT party_id FROM character_party WHERE character_id = $cid LIMIT 1")->fetchColumn();
$fx = new Effects($db);
if (isset($ops['level'])) {
    $lv = (int) $ops['level'];
    $xp = [1 => 0, 2 => 300, 3 => 900, 4 => 2700, 5 => 6500, 6 => 14000][$lv] ?? 6500;
    $db->prepare('UPDATE characters SET level = ?, experience = ?, max_hp = GREATEST(max_hp, ?), current_hp = GREATEST(max_hp, ?),
                  proficiency_bonus = ? WHERE id = ?')->execute([$lv, $xp, 10 * $lv, 10 * $lv, $lv >= 5 ? 3 : 2, $cid]);
}
if (isset($ops['gold'])) { $db->prepare('UPDATE characters SET gold = ? WHERE id = ?')->execute([(int) $ops['gold'], $cid]); }
foreach ($ops['companions'] ?? [] as $k) {
    $cs = new CompanionService($db);
    try { $cs->meet($pid, $k); $cs->recruit($pid, $k); } catch (Throwable $e) { fwrite(STDERR, "companion $k: {$e->getMessage()}\n"); }
}
foreach ($ops['flags'] ?? [] as $k => $v) { $fx->apply($pid, [['set_flag' => $k, 'value' => (string) $v]]); }
foreach ($ops['quests'] ?? [] as $q => $stage) {
    $r = $fx->apply($pid, [['start_quest' => $q]]);
    if ($stage) { $r = $fx->apply($pid, [['quest_stage' => ['quest' => $q, 'stage' => $stage]]]); }
}
if (isset($ops['at'])) {
    $db->prepare('UPDATE characters SET current_location_id = (SELECT id FROM locations WHERE location_key = ?) WHERE id = ?')
       ->execute([$ops['at'], $cid]);
}
// Levelling a seeded party leaves the ceremony waiting; it is not what is under test.
$db->exec("UPDATE pending_level_ups SET resolved_at = NOW() WHERE resolved_at IS NULL
           AND character_id IN (SELECT character_id FROM character_party WHERE party_id = $pid)");
echo json_encode(['party_id' => $pid]), "\n";
