<?php
/**
 * Free play has a front door: the Proving House is joined to Rivermark.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_freeplay_road.php`
 * after src/sql/migrations/2026-10-29-freeplay-road.sql.
 *
 * The bug this guards: new characters start in `_freeplay_yard`, and its
 * region had no road out, only the stair down to the Pit. "New groups can't
 * get out of there." It also checks that walking out of the door joins a
 * free-play party to Rivermark's module, and that nothing moves a party that
 * is already in a real module.
 *
 * Builds its own parties and characters and removes them. Safe against a
 * database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d='') { global $pass,$fail;
  if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n"; } }

$loc = fn (string $key): int => (int) $db->query(
    'SELECT id FROM locations WHERE location_key = ' . $db->quote($key))->fetchColumn();
$mod = fn (string $key): int => (int) $db->query(
    'SELECT id FROM modules WHERE module_key = ' . $db->quote($key))->fetchColumn();

$yard   = $loc('_freeplay_yard');
$cellar = $loc('_freeplay_cellar');
$gate   = $loc('west_gate');
$flagon = $loc('flagon_common_room');
ok('the Proving House exists', $yard > 0);
ok('the West Gate exists', $gate > 0);

$exit = function (int $from, int $to) use ($db): ?array {
    $s = $db->prepare('SELECT label, is_hidden, conditions_json FROM location_exits
                        WHERE from_location_id = ? AND to_location_id = ?');
    $s->execute([$from, $to]);
    return $s->fetch() ?: null;
};
$out  = $exit($yard, $gate);
$back = $exit($gate, $yard);
ok('a door out of the Proving House to the West Gate', $out !== null);
ok('and it is open and in plain sight',
   $out !== null && (int) $out['is_hidden'] === 0 && $out['conditions_json'] === null, json_encode($out));
ok('and a road back from the West Gate', $back !== null);

$engine = new LocationEngine($db);
$route = $engine->routeTo($yard, $flagon);
ok('the Golden Flagon is reachable from the Proving House', !empty($route), json_encode($route));

$freeplay  = $mod(FREE_PLAY_MODULE);
$rivermark = $mod('rivermark');
$oldCity   = $mod('old_city');

$made = []; $chars = [];
register_shutdown_function(function () use ($db, &$made, &$chars) {
    foreach ($made as $p) { $db->prepare('DELETE FROM character_party WHERE party_id = ?')->execute([$p]); }
    foreach ($chars as $c) { $db->prepare('DELETE FROM characters WHERE id = ?')->execute([$c]); }
    foreach ($made as $p) { $db->prepare('DELETE FROM parties WHERE id = ?')->execute([$p]); }
});
$probe = function (?int $module, string $name) use ($db, $yard, &$made, &$chars): array {
    $db->prepare('INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, ?)')
       ->execute([$module, "road probe $name"]);
    $pid = (int) $db->lastInsertId(); $made[] = $pid;
    $db->prepare(
        'INSERT INTO characters (name, race, class, level, strength, dexterity, constitution,
            intelligence, wisdom, charisma, max_hp, current_hp, armor_class, speed,
            proficiency_bonus, current_location_id)
         VALUES (?, "Human", "Fighter", 1, 12,12,12,12,12,12, 12,12, 14, 30, 2, ?)'
    )->execute(["__road_{$name}", $yard]);
    $cid = (int) $db->lastInsertId(); $chars[] = $cid;
    $db->prepare('INSERT INTO character_party (party_id, character_id) VALUES (?, ?)')
       ->execute([$pid, $cid]);
    $db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')->execute([$cid, $pid]);
    return [$pid, $cid];
};
$moduleOf = fn (int $pid) => $db->query('SELECT module_id FROM parties WHERE id = ' . $pid)->fetchColumn();

foreach ([['free play', $freeplay], ['no module', null]] as [$label, $start]) {
    [$pid, $cid] = $probe($start, str_replace(' ', '_', $label));
    $r = $engine->travel($cid, $gate);
    ok("$label: walks out of the front door", (int) $r['location_id'] === $gate, json_encode($r['location_id']));
    ok("$label: and is now in Rivermark's module", (int) $moduleOf($pid) === $rivermark,
       'module_id ' . var_export($moduleOf($pid), true));
    $engine->travel($cid, $yard);
    ok("$label: walking back in keeps Rivermark", (int) $moduleOf($pid) === $rivermark);
}

[$pid, $cid] = $probe($oldCity, 'old_city');
$engine->travel($cid, $gate);
ok('a party already in a module keeps it', (int) $moduleOf($pid) === $oldCity,
   'module_id ' . var_export($moduleOf($pid), true));

[$pid, $cid] = $probe($freeplay, 'cellar');
$engine->travel($cid, $cellar);
ok('the stair down leaves a free-play party in free play', (int) $moduleOf($pid) === $freeplay);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
