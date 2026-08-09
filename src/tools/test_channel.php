<?php
/**
 * Channel Divinity: Turn Undead, and Preserve Life.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_channel.php`.
 *
 * Turn Undead is the interesting one, because it is the first rule in the game
 * that needed the movement work to exist at all. "Must spend its turns trying
 * to move as far away as it can from you" was unimplementable while frightened
 * was a modifier on dice and nothing else; it forbids closing on its source
 * now, and the monster AI walks through the same reachFor() a player does. So
 * the assertion worth making is not that a save was rolled — it is that a
 * turned skeleton genuinely cannot come at the cleric afterwards.
 *
 * Uses a scratch party and character, because the use counter lives in
 * world_flags and is keyed by party. Both are removed at the end.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$engine = new CombatEngine(db());
$invoke = function (string $name, ...$args) use ($engine) {
    $m = new ReflectionMethod(CombatEngine::class, $name);
    $m->setAccessible(true);
    return $m->invoke($engine, ...$args);
};

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
function threw(string $n, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        ok($n, false, 'nothing was thrown');
    } catch (InvalidArgumentException $e) {
        ok($n, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
    }
}

echo "== the allowance ==\n";
same('one use at 2nd', 1, Rules::channelDivinityUses(2));
same('and two at 6th', 2, Rules::channelDivinityUses(6));
ok('a cleric has it at 2nd', Rules::hasFeature('Cleric', 2, 'channel_divinity'));
ok('and not at 1st', !Rules::hasFeature('Cleric', 1, 'channel_divinity'));
ok('a paladin does not have this one', !Rules::hasFeature('Paladin', 6, 'channel_divinity'));
same('a 5th-level cleric preserves twenty-five points', 25, Rules::preserveLifePool(5));
ok('an hour gives it back',
    in_array('channel_divinity', CombatEngine::SHORT_REST_FEATURES, true));

// A real party, because the counter is a world flag keyed by party id.
db()->exec("INSERT INTO parties (user_id, name) VALUES (NULL, '__channel_probe')");
$partyId = (int) db()->lastInsertId();
db()->exec("INSERT INTO characters (name, race, class, subclass, level, strength, dexterity,
    constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
    speed, proficiency_bonus)
    VALUES ('__channel_cleric', 'Human', 'Cleric', 'Life Domain', 5, 10,10,14,10,18,10, 33,33, 16, 30, 3)");
$cid = (int) db()->lastInsertId();
db()->prepare('INSERT INTO character_party (party_id, character_id) VALUES (?, ?)')
    ->execute([$partyId, $cid]);
$clearFlags = fn() => db()->prepare('DELETE FROM world_flags WHERE party_id = ?')->execute([$partyId]);

function board(int $cid, array $pc = [], array $foes = []): array
{
    $foes = $foes ?: [['creature_type' => 'undead', 'x' => 6, 'y' => 5]];
    $combatants = [array_merge([
        'character_id' => $cid, 'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party',
        'name' => 'Aldric', 'class' => 'Cleric', 'subclass' => 'Life Domain', 'level' => 5,
        'x' => 4, 'y' => 5, 'str' => 10, 'dex' => 10, 'con' => 14, 'int' => 10,
        'wis' => 18, 'cha' => 10, 'prof' => 3, 'speed' => 30, 'move_left' => 30,
        'hp' => 33, 'max_hp' => 33, 'ac' => 16, 'alive' => true, 'reach_ft' => 5,
        'conditions' => [], 'boons' => [], 'feats' => [], 'concentrating' => null,
        'action_used' => false, 'attacks_made' => 0, 'temp_hp' => 0, 'stable' => false,
        'surprised' => false, 'death_saves' => ['success' => 0, 'fail' => 0],
    ], $pc)];
    foreach ($foes as $i => $f) {
        $combatants[] = array_merge([
            'cid' => 'mon_' . $i, 'type' => 'monster', 'side' => 'foe',
            'name' => 'Skeleton ' . $i, 'x' => 6, 'y' => 5,
            'str' => 10, 'dex' => 14, 'con' => 15, 'int' => 6, 'wis' => 8, 'cha' => 5,
            'prof' => 2, 'level' => 1, 'speed' => 30, 'move_left' => 30,
            'hp' => 13, 'max_hp' => 13, 'ac' => 13, 'alive' => true, 'reach_ft' => 5,
            'conditions' => [], 'boons' => [], 'concentrating' => null, 'temp_hp' => 0,
        ], $f);
    }
    return [
        'round' => 1, 'turn_index' => 0,
        'order' => array_column($combatants, 'cid'), 'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16)), 'w' => 16, 'h' => 12],
        'log' => [], 'events' => [], 'combatants' => $combatants,
    ];
}

echo "\n== Turn Undead ==\n";
$clearFlags();
// Wisdom 18 and proficiency 3 make the DC 14; a skeleton saves at -1. Most
// fail, so a handful of runs will show both outcomes without forcing a die.
$turnedAny = false;
$heldAny = false;
for ($i = 0; $i < 40; $i++) {
    $clearFlags();
    $s = $invoke('doChannelDivinity', board($cid), 'turn_undead');
    $mon = $s['combatants'][1];
    if (Conditions::has($mon, 'frightened')) {
        $turnedAny = true;
        // The source is recorded, which is what makes it a rule about movement
        // rather than only about dice.
        if (Conditions::fearedBy($mon) !== ['pc_1']) {
            ok('the turned creature fears the cleric', false, 'source not recorded');
        }
    } else {
        $heldAny = true;
    }
}
ok('the undead can be turned', $turnedAny);
ok('and can also hold', $heldAny);

// The point of the whole thing: a turned skeleton cannot close.
$clearFlags();
$turned = board($cid, [], [['creature_type' => 'undead', 'x' => 9, 'y' => 5]]);
$turned['combatants'][1]['conditions'] = [['key' => 'frightened', 'source_cid' => 'pc_1']];
$reachFor = new ReflectionMethod(CombatEngine::class, 'reachFor');
$reachFor->setAccessible(true);
$cells = $reachFor->invoke($engine, $turned, $turned['combatants'][1]);
$closer = 0;
foreach (array_keys($cells) as $key) {
    [$x, $y] = BattleGrid::parseKey($key);
    if (BattleGrid::cells($x, $y, 4, 5) < BattleGrid::cells(9, 5, 4, 5)) {
        $closer++;
    }
}
same('a turned creature is offered no cell nearer the cleric', 0, $closer);
ok('though it may still back away', count($cells) > 1);

// Only the undead, and only within thirty feet.
$clearFlags();
$mixed = $invoke('doChannelDivinity', board($cid, [], [
    ['creature_type' => 'humanoid', 'x' => 6, 'y' => 5],
    ['creature_type' => 'undead', 'x' => 15, 'y' => 11],
]), 'turn_undead');
ok('a living foe is untouched', !Conditions::has($mixed['combatants'][1], 'frightened'));
ok('and one beyond thirty feet is out of reach',
    !Conditions::has($mixed['combatants'][2], 'frightened'));

echo "\n== Preserve Life ==\n";
$clearFlags();
$hurt = board($cid, ['hp' => 10], []);
$hurt['combatants'][] = [
    'character_id' => 0, 'cid' => 'pc_2', 'type' => 'pc', 'side' => 'party',
    'name' => 'Wren', 'class' => 'Fighter', 'level' => 5, 'x' => 5, 'y' => 5,
    'hp' => 4, 'max_hp' => 40, 'alive' => true, 'conditions' => [], 'boons' => [],
    'temp_hp' => 0, 'concentrating' => null, 'reach_ft' => 5,
];
$hurt['order'] = ['pc_1', 'pc_2'];
$healed = $invoke('doChannelDivinity', $hurt, 'preserve_life');
$wren = $healed['combatants'][1];
ok('the worst hurt is healed first', (int) $wren['hp'] > 4, "Wren on {$wren['hp']}");
ok('and nobody is taken past half their maximum',
    (int) $wren['hp'] <= intdiv(40, 2), "Wren on {$wren['hp']} of 40");

echo "\n== the allowance is real ==\n";
$clearFlags();
$once = $invoke('doChannelDivinity', board($cid), 'turn_undead');
threw('a second channel in one rest is refused',
    fn() => $invoke('doChannelDivinity', board($cid), 'turn_undead'), 'spent');

$clearFlags();
threw('an unknown channel is refused',
    fn() => $invoke('doChannelDivinity', board($cid), 'smite_them_all'), 'No such channel');
threw('and a cleric without the domain cannot preserve life',
    fn() => $invoke('doChannelDivinity', board($cid, ['subclass' => null]), 'preserve_life'),
    'domain gift');

db()->prepare('DELETE FROM world_flags WHERE party_id = ?')->execute([$partyId]);
db()->prepare('DELETE FROM character_party WHERE party_id = ?')->execute([$partyId]);
db()->prepare('DELETE FROM characters WHERE id = ?')->execute([$cid]);
db()->prepare('DELETE FROM parties WHERE id = ?')->execute([$partyId]);

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
