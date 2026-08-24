<?php
/**
 * The item catalogue: can the copy desk write a +1 sword and can Studio drop it?
 *
 * NEEDS the database. Creates one item, drops it, refuses the mistakes the
 * loader would refuse, and deletes it. Safe against a database somebody is
 * playing on: the key is unique per run and deleteItem refuses if a character
 * is carrying it — which nothing here creates.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$ed = new ContentEditor($db);

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $n\n"; return; }
    $fail++;
    echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n";
}
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}
function threw(callable $fn): ?string
{
    try { $fn(); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
}

$tag = 'it' . bin2hex(random_bytes(3));
$key = $tag . '_plus_sword';
$itemId = null;
$locId = (int) $db->query("SELECT id FROM locations WHERE location_key = 'flagon_common_room'")->fetchColumn();

echo "== the catalogue ==\n";
$list = $ed->listItems();
ok('the catalogue has items', count($list) > 0, (string) count($list));
$keys = array_column($list, 'item_key');
ok('the +1 longsword is already in it', in_array('longsword_fine', $keys, true));

echo "== writing a +1 sword ==\n";
$msg = threw(fn () => $ed->saveItem(['item_key' => 'Bad Key', 'name' => 'No', 'item_type' => 'weapon']));
ok('a bad key is refused', $msg !== null && str_contains((string) $msg, 'lowercase'));

$msg = threw(fn () => $ed->saveItem(['item_key' => $key, 'name' => 'No', 'item_type' => 'wand']));
ok('an unknown type is refused', $msg !== null && str_contains((string) $msg, 'item type'));

$made = $ed->saveItem([
    'item_key'    => $key,
    'name'        => 'Shortsword +1',
    'item_type'   => 'weapon',
    'rarity'      => 'uncommon',
    'description' => 'A test blade. The edge stays true.',
    'weight'      => 2,
    'value_gp'    => 300,
    'damage_dice' => '1d6',
    'damage_type' => 'piercing',
    'icon'        => 'sword',
    'properties'  => [
        'finesse'       => true,
        'light'         => true,
        'martial'       => true,
        'attack_bonus'  => 1,
        'damage_bonus'  => 1,
    ],
]);
$itemId = (int) $made['id'];
ok('the sword is saved', $itemId > 0);
same('under the key we gave it', $key, $made['item_key']);
same('named as a +1', 'Shortsword +1', $made['name']);
same('its attack bonus is 1', 1, (int) ($made['properties']['attack_bonus'] ?? 0));
same('and its damage bonus is 1', 1, (int) ($made['properties']['damage_bonus'] ?? 0));

$again = $ed->saveItem([
    'id'          => $itemId,
    'item_key'    => $key,
    'name'        => 'Shortsword +1',
    'item_type'   => 'weapon',
    'rarity'      => 'rare',
    'description' => 'A test blade. The edge stays true.',
    'weight'      => 2,
    'value_gp'    => 400,
    'damage_dice' => '1d6',
    'damage_type' => 'piercing',
    'properties'  => $made['properties'],
]);
same('a second save rewrites the same row', $itemId, (int) $again['id']);
same('and the rarity moved', 'rare', $again['rarity']);

$msg = threw(fn () => $ed->saveItem([
    'item_key' => $tag . '_scroll',
    'name'     => 'Bad Scroll',
    'item_type'=> 'scroll',
    'properties' => ['use_effect' => ['cast' => 'not_a_spell']],
]));
ok('a scroll of nothing is refused', $msg !== null && str_contains((string) $msg, 'No spell'));

echo "== drops ==\n";
ok('there is a room to drop it in', $locId > 0);
if ($locId > 0) {
    $here = $ed->addLocationItem($locId, $key);
    $dropped = array_values(array_filter(
        $here['items'] ?? [],
        static fn ($it) => ($it['item_key'] ?? '') === $key
    ));
    ok('studio can drop the new sword', $dropped !== []);
    if ($dropped) {
        $here = $ed->removeLocationItem((int) $dropped[0]['id']);
        $still = array_values(array_filter(
            $here['items'] ?? [],
            static fn ($it) => ($it['item_key'] ?? '') === $key
        ));
        ok('and pick it back up', $still === []);
    }
}

echo "== export ==\n";
$tmp = sys_get_temp_dir() . '/rpg-item-export-' . $tag;
mkdir($tmp, 0o775, true);
try {
    $n = (new ContentExporter($db, $tmp))->exportItems();
    ok('export writes items', $n > 0, (string) $n);
    $file = $tmp . '/items/' . $key . '.json';
    ok('including the new sword', is_file($file));
    $doc = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    same('the file is keyed', $key, $doc['item_key'] ?? null);
    same('and still +1', 1, (int) ($doc['properties']['attack_bonus'] ?? 0));
    $againN = (new ContentExporter($db, $tmp))->exportItems();
    ok('a second export of the same tree writes nothing', $againN === 0, (string) $againN);
} finally {
    foreach (glob($tmp . '/items/*.json') ?: [] as $f) {
        unlink($f);
    }
    if (is_dir($tmp . '/items')) {
        rmdir($tmp . '/items');
    }
    if (is_dir($tmp)) {
        rmdir($tmp);
    }
}

echo "== delete ==\n";
$gone = $ed->deleteItem($itemId);
ok('an unused sword can be deleted', !empty($gone['deleted']));
$left = $db->prepare('SELECT id FROM items WHERE item_key = ?');
$left->execute([$key]);
ok('and it is gone', $left->fetchColumn() === false);

$held = $ed->saveItem([
    'item_key'  => $tag . '_held',
    'name'      => 'Held Blade',
    'item_type' => 'weapon',
]);
$charId = (int) $db->query('SELECT id FROM characters LIMIT 1')->fetchColumn();
if ($charId > 0) {
    $db->prepare('INSERT INTO character_inventory (character_id, item_id, quantity) VALUES (?,?,1)')
        ->execute([$charId, (int) $held['id']]);
    $msg = threw(fn () => $ed->deleteItem((int) $held['id']));
    ok('a carried item is refused', $msg !== null && str_contains((string) $msg, 'carrying'));
    $db->prepare('DELETE FROM character_inventory WHERE item_id = ?')->execute([(int) $held['id']]);
}
$ed->deleteItem((int) $held['id']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
