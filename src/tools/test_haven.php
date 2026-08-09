<?php
/**
 * A beaten party must wake in its OWN module.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_haven.php`.
 *
 * The bug this guards: CombatEngine's defeat path had `flagon_common_room`
 * written into it as a literal, so losing a fight anywhere in the Old City woke
 * the whole party in Rivermark — a region their module has no exit to, and from
 * which every street of the other game is walkable. Every path a player could
 * WALK respected the module boundary. The one that moved them without walking
 * did not, and it took a real playthrough to find.
 *
 * Builds its own parties and removes them. Safe against a database in use.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$gen = new CharacterGenerator($db);
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d='') { global $pass,$fail;
  if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n"; } }

$made = [];
// The Undervault matters most of the three and is the reason this list is not
// just the two authored games. Its dungeon levels are generated per delve and
// deleted when the delve ends, so a party beaten underground must wake at a
// room that will still exist afterwards — `uv_camp` is authored, persistent,
// and the module's own start. If a generated room ever became the haven, the
// party would wake somewhere that is about to be cascaded away.
foreach ([
    'rivermark'  => 'flagon_common_room',
    'old_city'   => 'oldcity_stair',
    'undervault' => 'uv_camp',
] as $mod => $want) {
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL,
        (SELECT id FROM modules WHERE module_key=?), ?)")->execute([$mod, "haven probe $mod"]);
    $pid = (int) $db->lastInsertId();
    $made[] = $pid;
    $h = $gen->havenFor($pid);
    $key = $db->query('SELECT location_key FROM locations WHERE id = ' . (int) ($h['id'] ?? 0))->fetchColumn();
    ok("$mod wakes at $want", $key === $want, "woke at " . var_export($key, true));

    // And that it is inside the party's own module, which is the actual rule.
    $sameModule = (int) $db->query(
        'SELECT COUNT(*) FROM locations l
           JOIN regions r ON r.id = l.region_id
          WHERE l.id = ' . (int) ($h['id'] ?? 0) . '
            AND r.module_id = (SELECT module_id FROM parties WHERE id = ' . $pid . ')')->fetchColumn();
    ok("$mod haven is inside its own module", $sameModule === 1);
    ok("$mod log names the place", ($h['name'] ?? '') !== '', json_encode($h));
}

// A party from before modules existed still gets somewhere sane.
$db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL, NULL, 'legacy probe')")->execute();
$pid = (int) $db->lastInsertId(); $made[] = $pid;
$h = $gen->havenFor($pid);
ok('a module-less party still gets a haven', ($h['id'] ?? 0) > 0, json_encode($h));

foreach ($made as $p) { $db->prepare('DELETE FROM parties WHERE id=?')->execute([$p]); }
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
