<?php
/**
 * InventoryService, which had no tests at all until the economy grew teeth:
 * slots and their capacities, the enchantment vocabulary in `properties`,
 * consumables, the shop, and the loot roller's arithmetic.
 *
 * NEEDS the database — items are rows and equipping is a JOIN — and works on
 * a scratch character inside a transaction it rolls back, the
 * test_levelup.php pattern. Safe against a database somebody is playing on.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$inv = new InventoryService($db);

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        echo "  ok   $n\n";
    } else {
        $fail++;
        echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n";
    }
}
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

/** @return int the id of a named content item, or fail loudly. */
function itemId(PDO $db, string $key): int
{
    $s = $db->prepare('SELECT id FROM items WHERE item_key = ?');
    $s->execute([$key]);
    $id = $s->fetchColumn();
    if ($id === false) {
        fwrite(STDERR, "content item '{$key}' is missing — load content.sql first\n");
        exit(1);
    }
    return (int) $id;
}

function give(PDO $db, int $characterId, string $key, int $qty = 1): int
{
    $db->prepare(
        'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped) VALUES (?,?,?,0)'
    )->execute([$characterId, itemId($db, $key), $qty]);
    return (int) $db->lastInsertId();
}

function equippedKeys(InventoryService $inv, int $characterId): array
{
    $out = [];
    foreach ($inv->list($characterId) as $row) {
        if ((int) $row['is_equipped'] === 1) {
            $out[] = $row['name'];
        }
    }
    sort($out);
    return $out;
}

$db->beginTransaction();
try {
    // A bare fighter: no kit, no gold, AC written by hand so the recalc has
    // something to correct.
    $db->prepare(
        'INSERT INTO characters
            (name, race, class, level, experience, strength, dexterity, constitution,
             intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
             proficiency_bonus, gold)
         VALUES (?, ?, ?, 1, 0, 14, 14, 12, 10, 10, 10, 20, 10, 10, 2, 100)'
    )->execute(['_test_' . bin2hex(random_bytes(4)), 'Human', 'Fighter']);
    $cid = (int) $db->lastInsertId();
    $charRow = function () use ($db, $cid): array {
        $s = $db->prepare('SELECT * FROM characters WHERE id = ?');
        $s->execute([$cid]);
        return $s->fetch();
    };

    echo "Slots\n";

    $sword = give($db, $cid, 'longsword');
    $mace = give($db, $cid, 'mace');
    $inv->equip($cid, $sword);
    $inv->equip($cid, $mace);
    same('a second weapon swaps the first out', ['Mace'], equippedKeys($inv, $cid));

    $mail = give($db, $cid, 'chain_shirt');
    $shield = give($db, $cid, 'shield_warded');
    $inv->equip($cid, $mail);
    $inv->equip($cid, $shield);
    ok('body armour and a shield coexist with the weapon',
        count(equippedKeys($inv, $cid)) === 3);

    $leather = give($db, $cid, 'leather_armor');
    $inv->equip($cid, $leather);
    $worn = equippedKeys($inv, $cid);
    ok('a second suit swaps the body slot and leaves the shield',
        in_array('Leather Armor', $worn, true)
        && in_array('Warded Shield', $worn, true)
        && !in_array('Chain Shirt', $worn, true));

    $ringA = give($db, $cid, 'ring_of_protection');
    $ringB = give($db, $cid, 'ring_of_protection');
    $ringC = give($db, $cid, 'ring_of_protection');
    $inv->equip($cid, $ringA);
    $inv->equip($cid, $ringB);
    ok('two rings fit two hands', count(equippedKeys($inv, $cid)) === 5);
    $inv->equip($cid, $ringC);
    $rings = array_filter($inv->list($cid), fn ($r) => (int) $r['is_equipped'] === 1 && $r['slot'] === 'ring');
    same('a third ring swaps the oldest, never a second slot', 2, count($rings));
    $stillOn = array_column($rings, 'id');
    ok('and the one swapped out is the first worn',
        !in_array($ringA, $stillOn) && in_array($ringB, $stillOn) && in_array($ringC, $stillOn));

    $paper = give($db, $cid, 'grain_tally');
    $refused = false;
    try {
        $inv->equip($cid, $paper);
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    ok('a slotless misc item cannot be worn', $refused);

    echo "\nEnchantment arithmetic\n";

    // Leather 11 + DEX 2 + warded shield (2 armour + 1 enchantment)
    // + two rings of protection.
    same('AC counts armour, shield, its +1, and both rings', 18,
        (int) $charRow()['armor_class']);
    $inv->equip($cid, $ringB, false);
    $inv->equip($cid, $ringC, false);
    same('and lets go of what is taken off', 16, (int) $charRow()['armor_class']);

    $withRing = $charRow();
    $withRing['equip_save_bonus'] = 1;
    $bare = $charRow();
    ok('a stamped save bonus is one more on every save',
        Rules::savingThrow($withRing, 'wis')['total']
            === Rules::savingThrow($bare, 'wis')['total'] + 1);
    $labels = array_column(Rules::savingThrow($withRing, 'wis')['parts'], 'label');
    ok('and the sheet can say where it came from', in_array('Equipment', $labels, true));

    echo "\nThe shop\n";

    $gold = (int) $charRow()['gold'];
    $r = $inv->buy($cid, itemId($db, 'antitoxin'), 'osric_apothecary');
    ok('buying pays the shop price', (int) $r['character']['gold'] < $gold);
    $stock = $db->prepare('SELECT stock FROM shop_inventory WHERE npc_key = ? AND item_id = ?');
    $stock->execute(['osric_apothecary', itemId($db, 'antitoxin')]);
    same('and the shelf empties by one', 2, (int) $stock->fetchColumn());

    $refused = false;
    try {
        $inv->buy($cid, itemId($db, 'ring_of_protection'), 'osric_apothecary');
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    ok('a shop only sells what it stocks', $refused);

    $db->prepare('UPDATE characters SET gold = 0 WHERE id = ?')->execute([$cid]);
    $refused = false;
    try {
        $inv->buy($cid, itemId($db, 'antitoxin'), 'osric_apothecary');
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    ok('an empty purse is refused', $refused);

    $refused = false;
    try {
        $inv->sell($cid, $leather);
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    ok('worn gear cannot be sold off a back', $refused);

    $inv->equip($cid, $mace, false);
    $r = $inv->sell($cid, $mace);
    // Mace value 5 gp → floor(5/2) = 2.
    same('a sale pays half list, floored', 2, (int) $r['character']['gold']);

    echo "\nConsumables\n";

    same('a greater potion heals by its own dice', '4d4+4',
        InventoryService::healExpression(['properties' => '{"heals":"4d4+4"}', 'damage_dice' => null]));

    $potion = give($db, $cid, 'potion_healing');
    $r = $inv->usePotion($cid, $potion);
    ok('drinking heals and clamps to the maximum',
        $r['healed'] >= 4 && (int) $r['character']['current_hp'] <= 20);

    $phial = give($db, $cid, 'antitoxin');
    $refused = false;
    try {
        $inv->usePotion($cid, $phial);
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    ok('a combat boon is refused outside a fight', $refused);

    $refused = false;
    try {
        $inv->usePotion($cid, $paper);
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    ok('paper is not a drink', $refused);

    echo "\nHanding things over\n";

    $mkChar = function (string $class = 'Cleric') use ($db): int {
        $db->prepare(
            'INSERT INTO characters
                (name, race, class, level, experience, strength, dexterity, constitution,
                 intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
                 proficiency_bonus, gold)
             VALUES (?, ?, ?, 1, 0, 10, 12, 12, 10, 14, 10, 18, 18, 10, 2, 0)'
        )->execute(['_test_' . bin2hex(random_bytes(4)), 'Human', $class]);
        return (int) $db->lastInsertId();
    };
    $joinParty = function (int $partyId, int $characterId) use ($db): void {
        $db->prepare('INSERT INTO character_party (party_id, character_id) VALUES (?, ?)')
            ->execute([$partyId, $characterId]);
    };
    $db->prepare('INSERT INTO parties (name) VALUES (?)')->execute(['_test_party']);
    $partyId = (int) $db->lastInsertId();
    $mate = $mkChar();
    $joinParty($partyId, $cid);
    $joinParty($partyId, $mate);

    // A stranger: same account, different party. Ownership is not enough — kit
    // must not walk between two playthroughs.
    $db->prepare('INSERT INTO parties (name) VALUES (?)')->execute(['_test_other_party']);
    $otherParty = (int) $db->lastInsertId();
    $stranger = $mkChar('Rogue');
    $joinParty($otherParty, $stranger);

    $countOf = function (int $who, string $name) use ($inv): int {
        $n = 0;
        foreach ($inv->list($who) as $row) {
            if ($row['name'] === $name) {
                $n += (int) $row['quantity'];
            }
        }
        return $n;
    };

    $stack = give($db, $cid, 'potion_healing', 5);
    $r = $inv->give($cid, $stack, $mate, 2);
    same('two of five change hands', 2, $r['moved']);
    same('and three stay behind', 3, $countOf($cid, 'Potion of Healing'));
    same('the other end has two', 2, $countOf($mate, 'Potion of Healing'));

    // The receiver already has a stack, so this must merge rather than open a
    // second row of the same thing.
    $inv->give($cid, $stack, $mate, 1);
    $rows = array_filter($inv->list($mate), fn ($x) => $x['name'] === 'Potion of Healing');
    same('a second handover merges into one stack', 1, count($rows));
    same('— of three', 3, $countOf($mate, 'Potion of Healing'));

    // Whatever is left, with no quantity asked for.
    $inv->give($cid, $stack, $mate);
    same('the rest of the stack goes in one go', 0, $countOf($cid, 'Potion of Healing'));
    same('and lands whole', 5, $countOf($mate, 'Potion of Healing'));

    // Body armour the fighter is not already carrying, so the counts below are
    // about this row rather than the kit the slot tests left lying around.
    // Equipping it swaps the leather off; handing it over must leave the body
    // slot genuinely empty and the AC saying so.
    $worn = give($db, $cid, 'pit_wardens_coat');
    $inv->equip($cid, $worn);
    $armoured = (int) $charRow()['armor_class'];
    $inv->give($cid, $worn, $mate);
    same('worn gear leaves the giver', 0, $countOf($cid, "Pit Warden's Coat"));
    ok('and their AC is recalculated rather than left claiming it is still on',
        (int) $charRow()['armor_class'] < $armoured,
        "was {$armoured}, now " . $charRow()['armor_class']);
    $landed = array_values(array_filter($inv->list($mate),
        fn ($x) => $x['name'] === "Pit Warden's Coat"));
    same('it arrives unequipped', 0, (int) $landed[0]['is_equipped']);

    $refuse = function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (InvalidArgumentException $e) {
            return true;
        }
    };
    $loose = give($db, $cid, 'holy_symbol');
    ok('somebody in another party cannot be handed anything',
        $refuse(fn () => $inv->give($cid, $loose, $stranger)));
    ok('and it is still in the giver\'s bag afterwards',
        $countOf($cid, 'Pewter Sun-Disc') === 1);
    ok('handing something to yourself is refused',
        $refuse(fn () => $inv->give($cid, $loose, $cid)));
    ok('so is handing over what you are not carrying',
        $refuse(fn () => $inv->give($mate, $loose, $cid)));
} finally {
    $db->rollBack();
}

echo "\nThe loot roller\n";

$sure = CombatEngine::rollLoot([
    ['item' => 'oil_of_embers'],
    ['item' => 'oil_of_embers', 'qty' => 2],
    ['gold' => 7],
    ['gold' => '2d6'],
]);
same('certain drops accumulate by key', ['oil_of_embers' => 3], $sure['items']);
ok('gold takes flat numbers and dice both', $sure['gold'] >= 9 && $sure['gold'] <= 19,
    "got {$sure['gold']}");
same('an empty spec drops nothing', ['items' => [], 'gold' => 0], CombatEngine::rollLoot([]));
same('junk entries are ignored rather than fatal',
    ['items' => [], 'gold' => 0], CombatEngine::rollLoot(['nonsense', ['chance' => 50]]));

$hits = 0;
for ($i = 0; $i < 300; $i++) {
    $roll = CombatEngine::rollLoot([['item' => 'x', 'chance' => 50]]);
    $hits += isset($roll['items']['x']) ? 1 : 0;
}
ok('a 50% chance lands roughly half the time', $hits > 90 && $hits < 210, "landed {$hits}/300");

echo "\nThe on-hit rider parser\n";
same('reads dice and type', ['dice' => '1d4', 'type' => 'fire'], CombatEngine::parseOnHit('1d4 fire'));
same('reads a modifier', ['dice' => '2d6+1', 'type' => 'cold'], CombatEngine::parseOnHit('2d6+1 cold'));
same('refuses junk', null, CombatEngine::parseOnHit('burning'));
same('refuses a bare number', null, CombatEngine::parseOnHit('4 fire'));

echo "\n" . ($fail === 0 ? "0 failed" : "{$fail} FAILED") . " ({$pass} passed)\n";
exit($fail === 0 ? 0 : 1);
