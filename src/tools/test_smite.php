<?php
/**
 * Divine Smite: a slot held for the next thing you hit.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_smite.php`.
 *
 * The deviation this file mostly exists to pin down: the SRD lets a Paladin
 * decide AFTER the hit, and this engine makes them decide before. What is kept
 * is the part that carries the cost — the slot is spent only when the blow
 * lands. A miss costs nothing, a ranged attack costs nothing, and a Smite held
 * and never landed is dropped at the top of the next turn rather than carried.
 * If any of those stop being true the feature has quietly become a tax on
 * missing, which is the thing arming it before the swing could have broken.
 *
 * Uses a scratch character row, because spending a slot writes
 * `characters.spell_slots_json`. It is deleted again at the end.
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

echo "== the dice ==\n";
same('a 1st-level slot is 2d8', 2, Rules::smiteDice(1));
same('a 2nd is 3d8', 3, Rules::smiteDice(2));
same('a 3rd is 4d8', 4, Rules::smiteDice(3));
same('and it stops at 5d8 however deep the slot', 5, Rules::smiteDice(9));
same('one more against the undead', 3, Rules::smiteDice(1, true));
same('capped at 6d8 there', 6, Rules::smiteDice(9, true));
same('a paladin has it at 2nd', true, Rules::hasFeature('Paladin', 2, 'divine_smite'));
same('and not at 1st, when they have no slot to burn', false,
    Rules::hasFeature('Paladin', 1, 'divine_smite'));
same('a fighter never has it', false, Rules::hasFeature('Fighter', 6, 'divine_smite'));

// A real character, because arming reads the slot table and spending writes it.
db()->exec("INSERT INTO characters (name, race, class, level, strength, dexterity,
    constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
    speed, proficiency_bonus, spell_slots_json)
    VALUES ('__smite_probe', 'Human', 'Paladin', 5, 16,10,14,10,10,14, 40,40, 18, 30, 3, '{}')");
$cid = (int) db()->lastInsertId();

function board(int $cid, array $pc = [], array $foe = []): array
{
    return [
        'round' => 1, 'turn_index' => 0, 'order' => ['pc_1', 'mon_1'], 'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16)), 'w' => 16, 'h' => 12],
        'log' => [], 'events' => [],
        'combatants' => [
            array_merge([
                'character_id' => $cid, 'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party',
                'name' => 'Sunna', 'class' => 'Paladin', 'level' => 5, 'x' => 4, 'y' => 5,
                'str' => 16, 'dex' => 10, 'con' => 14, 'int' => 10, 'wis' => 10, 'cha' => 14,
                'prof' => 3, 'speed' => 30, 'move_left' => 30, 'reach_ft' => 5,
                'hp' => 40, 'max_hp' => 40, 'ac' => 18, 'alive' => true,
                'conditions' => [], 'boons' => [], 'feats' => [], 'concentrating' => null,
                'action_used' => false, 'attacks_made' => 0, 'reaction_used' => false,
                'smite' => 0, 'temp_hp' => 0, 'stable' => false, 'surprised' => false,
                'death_saves' => ['success' => 0, 'fail' => 0],
            ], $pc),
            array_merge([
                'cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
                'x' => 5, 'y' => 5, 'hp' => 400, 'max_hp' => 400, 'ac' => 1, 'alive' => true,
                'creature_type' => 'humanoid', 'reach_ft' => 5,
                'conditions' => [], 'boons' => [], 'concentrating' => null, 'temp_hp' => 0,
            ], $foe),
        ],
    ];
}
$SWORD = ['name' => 'Longsword', 'bonus' => 99, 'damage' => '1d8+3',
          'damage_type' => 'slashing', 'reach' => 'melee',
          'range' => ['reach' => 5, 'normal' => null, 'long' => null], 'tags' => ['melee']];
$BOW = ['name' => 'Longbow', 'bonus' => 99, 'damage' => '1d8+3',
        'damage_type' => 'piercing', 'reach' => 'ranged',
        'range' => ['reach' => null, 'normal' => 150, 'long' => 600], 'tags' => ['ranged']];
$shot = fn(array $s, bool $melee = true) => ['dist' => 5, 'cover' => 0, 'melee' => $melee,
    'long' => false, 'crowded' => false, 'flank' => false, 'c' => $s['combatants'][1]];

$slots = fn() => json_decode(
    (string) db()->query("SELECT spell_slots_json FROM characters WHERE id = {$cid}")->fetchColumn(),
    true
) ?: [];
$reset = fn() => db()->prepare('UPDATE characters SET spell_slots_json = ? WHERE id = ?')
    ->execute(['{}', $cid]);

echo "\n== holding one ==\n";
$reset();
$armed = $invoke('doSmite', board($cid), 2);
same('the slot level is held on the paladin', 2, (int) $armed['combatants'][0]['smite']);
same('and nothing is spent yet', [], $slots());
threw('a level they have no slots for is refused',
    fn() => $invoke('doSmite', board($cid), 3), 'No slot of that level');

$notPaladin = board($cid, ['class' => 'Fighter']);
threw('and a fighter cannot hold one', fn() => $invoke('doSmite', $notPaladin, 1), 'no Smite');

echo "\n== spending it ==\n";
$reset();
$hit = $invoke('resolveAttack', board($cid, ['smite' => 1]), 'pc_1', 'mon_1', $SWORD, null,
    $shot(board($cid)));
same('a landed blow burns the slot', ['1' => 1], $slots());
same('and the hold is cleared', 0, (int) $hit['combatants'][0]['smite']);
$dealt = 400 - (int) $hit['combatants'][1]['hp'];
// 1d8+3 weapon plus 2d8 radiant: at least 1+3+2, at most 8+3+16.
ok('the radiant lands on top of the weapon', $dealt >= 6 && $dealt <= 27, "dealt {$dealt}");
ok('and the log says so',
    (bool) array_filter($hit['log'], fn($l) => str_contains($l, 'radiant')));

// A miss must cost nothing. Forced by an armour class nothing can beat.
$reset();
$missBoard = board($cid, ['smite' => 2], ['ac' => 99]);
$missed = $invoke('resolveAttack', $missBoard, 'pc_1', 'mon_1',
    array_merge($SWORD, ['bonus' => -99]), null, $shot($missBoard));
same('a miss spends no slot', [], $slots());
same('and the smite is still held', 2, (int) $missed['combatants'][0]['smite']);

// Melee only.
$reset();
$ranged = board($cid, ['smite' => 1]);
$shotAt = $invoke('resolveAttack', $ranged, 'pc_1', 'mon_1', $BOW, null, $shot($ranged, false));
same('an arrow cannot carry a smite', [], $slots());
same('and the hold survives it', 1, (int) $shotAt['combatants'][0]['smite']);

echo "\n== the undead take it worse ==\n";
$reset();
$rolls = [];
for ($i = 0; $i < 60; $i++) {
    $b = board($cid, ['smite' => 1], ['creature_type' => 'undead']);
    $r = $invoke('resolveAttack', $b, 'pc_1', 'mon_1', $SWORD, null, $shot($b));
    $rolls[] = 400 - (int) $r['combatants'][1]['hp'];
    $reset();
}
// Misses are dropped before the floor is measured. A natural 1 is a miss
// whatever the bonus, so about three of sixty swings land nothing at all —
// the first version of this asserted on the raw minimum and reported "lowest
// was 0", which was the fumble rule working, not the smite failing.
$landed = array_values(array_filter($rolls, static fn ($d) => $d > 0));
ok('most swings landed, so the sample means something', count($landed) > 50,
    count($landed) . ' of 60');
// 1d8+3 weapon plus 3d8 radiant against the undead: the floor is 1+3+3.
ok('a smite on the undead rolls an extra die', min($landed) >= 7, 'lowest was ' . min($landed));

echo "\n== it does not survive the turn ==\n";
$carried = board($cid, ['smite' => 2]);
$next = $invoke('beginTurn', $carried);
same('a smite held and never landed is dropped', 0, (int) $next['combatants'][0]['smite']);

db()->prepare('DELETE FROM characters WHERE id = ?')->execute([$cid]);

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
