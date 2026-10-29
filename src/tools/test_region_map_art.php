<?php
/**
 * region_map's additive picture fields: node `icon`, neighbour `region_key` /
 * `region_type`, and the optional `painting` (art + pixel spots).
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_region_map_art.php`.
 * Reads only; builds nothing.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';
$db = db();
$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d='') { global $pass,$fail;
  if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n"; } }

// --- iconFor, no database ---------------------------------------------------
ok('the inn by key', RegionMapArt::iconFor('flagon_common_room', 'building', 'The Golden Flagon') === 'inn');
ok('the smithy by key', RegionMapArt::iconFor('the_anvil', 'building', 'The Anvil') === 'smithy');
ok('a shop by key', RegionMapArt::iconFor('tobins_goods', 'building', "Tobin's Goods") === 'shop');
ok('an inn by its room price', RegionMapArt::iconFor('x', 'building', 'The Lamb', 5) === 'inn');
ok('a temple by name', RegionMapArt::iconFor('x', 'building', 'Shrine of the Hour') === 'temple');
ok('a forge by name', RegionMapArt::iconFor('x', 'building', 'The Old Forge') === 'smithy');
ok('a stair street by name', RegionMapArt::iconFor('x', 'street', 'The Grain Stair') === 'stair');
ok('a plain street stays a street', RegionMapArt::iconFor('x', 'street', 'Granary Row') === 'street');
ok('a plain building is a house', RegionMapArt::iconFor('x', 'building', 'The Narrow House') === 'house');
ok('a gate keeps its type', RegionMapArt::iconFor('x', 'gate', 'The East Gate') === 'gate');
ok('a square keeps its type', RegionMapArt::iconFor('x', 'square', 'Market Square') === 'square');
ok('no painting for an unpainted region', RegionMapArt::paintingFor('quarry_wilds', [], []) === null);

// --- the live chart ---------------------------------------------------------
$loc = fn (string $key): int => (int) $db->query(
    'SELECT id FROM locations WHERE location_key = ' . $db->quote($key))->fetchColumn();
$gate = $loc('west_gate');
$region = (int) $db->query('SELECT region_id FROM locations WHERE id = ' . $gate)->fetchColumn();
ok('Rivermark has a West Gate', $gate > 0 && $region > 0);
$map = (new LocationEngine($db))->regionMap($region, $gate, null);
$byKey = [];
foreach ($map['nodes'] as $n) { $byKey[$n['key']] = $n; }
ok('every node has an icon', count(array_filter($map['nodes'], fn ($n) => !empty($n['icon']))) === count($map['nodes']));
ok('every node keeps its type', count(array_filter($map['nodes'], fn ($n) => isset($n['type']))) === count($map['nodes']));
ok('the Golden Flagon is an inn', ($byKey['flagon_common_room']['icon'] ?? '') === 'inn');
ok('the Anvil is a smithy', ($byKey['the_anvil']['icon'] ?? '') === 'smithy');
ok('the West Gate is a gate', ($byKey['west_gate']['icon'] ?? '') === 'gate');
ok('neighbours carry region_key and region_type',
   $map['neighbors'] && count(array_filter($map['neighbors'],
       fn ($nb) => !empty($nb['region_key']) && in_array($nb['region_type'], ['town', 'wilderness', 'dungeon'], true)))
     === count($map['neighbors']), json_encode($map['neighbors']));
$p = $map['painting'];
ok('Rivermark is painted', is_array($p) && $p['art'] === 'town_map' && $p['width'] === 640 && $p['height'] === 480);
$spot = [];
foreach ($p['nodes'] ?? [] as $s) { $spot[$s['key']] = $s; }
ok('a spot for every node sent', count($spot) === count($map['nodes']),
   implode(',', array_diff(array_keys($byKey), array_keys($spot))));
ok('spots carry the node id', ($spot['west_gate']['id'] ?? 0) === $gate);
ok('spots are on the painting', !array_filter($p['nodes'] ?? [],
   fn ($s) => $s['x'] < 0 || $s['y'] < 0 || $s['x'] >= 640 || $s['y'] >= 480));
$rk = array_column($p['neighbors'] ?? [], 'region_key');
ok('a spot for every road out', !array_diff(array_unique(array_column($map['neighbors'], 'region_key')), $rk),
   json_encode($rk));
ok('the JSON still encodes', json_encode($map) !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
