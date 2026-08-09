<?php
/**
 * Extra Attack: two swings from one Attack action, and only from that one.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_extra_attack.php`.
 *
 * The SRD gives Extra Attack at 5th to five classes, and Rules::MAX_LEVEL is 6
 * — so a martial character spends the last third of their career with it. They
 * spent it swinging once, because nothing counted attacks: `action_used` was a
 * boolean and the first swing closed it.
 *
 * The awkward part is not the allowance, it is telling a second swing of one
 * Attack action apart from an attack taken after Dodge. Both find the action
 * already spent. `attacks_made` counts up rather than down for exactly that
 * reason: zero means the Attack action has not been started, so a spent action
 * must have been spent on something else.
 *
 * Touches no rows.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$engine = new CombatEngine(db());
$doAttack = new ReflectionMethod(CombatEngine::class, 'doAttack');
$doAttack->setAccessible(true);
$doDodge = new ReflectionMethod(CombatEngine::class, 'doDodge');
$doDodge->setAccessible(true);

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

echo "== the allowance ==\n";
foreach (Rules::EXTRA_ATTACK_CLASSES as $class) {
    same("{$class} swings twice at 5th", 2, Rules::attacksPerAction($class, 5));
    same("and once at 4th", 1, Rules::attacksPerAction($class, 4));
}
same('a wizard never gets it', 1, Rules::attacksPerAction('Wizard', 6));
same('nor a rogue — theirs is Sneak Attack', 1, Rules::attacksPerAction('Rogue', 6));
same('a class nobody has heard of gets one', 1, Rules::attacksPerAction('Artificer', 6));
same('and so does level zero', 1, Rules::attacksPerAction('Fighter', 0));

echo "\n== who the engine hands it to ==\n";
$pc = fn(string $class, int $level) => ['type' => 'pc', 'class' => $class, 'level' => $level];
same('a 5th-level fighter', 2, CombatEngine::attacksAllowed($pc('Fighter', 5)));
same('a 3rd-level fighter', 1, CombatEngine::attacksAllowed($pc('Fighter', 3)));
// No stat block in the bestiary declares Multiattack, and `actions` is a list
// of what a creature MAY swing rather than a sequence it takes, so a monster
// asking for two would be inventing a rule its own stat block does not claim.
same('a monster gets one whatever its level', 1,
    CombatEngine::attacksAllowed(['type' => 'monster', 'class' => 'Fighter', 'level' => 6]));

echo "\n== spending them ==\n";
function board(int $level, array $extra = []): array
{
    return [
        'round' => 1,
        'turn_index' => 0,
        'order' => ['pc_1'],
        'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16)), 'w' => 16, 'h' => 12],
        'log' => [],
        'events' => [],
        'combatants' => [
            array_merge([
                // weaponFor() looks the equipped weapon up by this. No such
                // character exists, which is the point: the lookup finds
                // nothing and the fighter swings the unarmed default, so the
                // allowance is tested without a fixture in the database.
                'character_id' => 0,
                'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'name' => 'Wren',
                'class' => 'Fighter', 'level' => $level, 'x' => 4, 'y' => 5,
                'str' => 16, 'dex' => 12, 'con' => 14, 'int' => 10, 'wis' => 10, 'cha' => 10,
                'prof' => 3, 'speed' => 30, 'move_left' => 30,
                'hp' => 30, 'max_hp' => 30, 'ac' => 16, 'alive' => true,
                'conditions' => [], 'boons' => [], 'feats' => [],
                'action_used' => false, 'attacks_made' => 0, 'reach_ft' => 5,
            ], $extra),
            ['cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
             'x' => 5, 'y' => 5, 'hp' => 200, 'max_hp' => 200, 'ac' => 5, 'alive' => true,
             'conditions' => [], 'boons' => [], 'reach_ft' => 5],
        ],
    ];
}

$s = board(5);
$s = $doAttack->invoke($engine, $s, 'mon_1');
same('the first swing is taken', 1, (int) $s['combatants'][0]['attacks_made']);
ok('and it spends the action', !empty($s['combatants'][0]['action_used']));

$s = $doAttack->invoke($engine, $s, 'mon_1');
same('the second is allowed', 2, (int) $s['combatants'][0]['attacks_made']);

$third = false;
try {
    $doAttack->invoke($engine, $s, 'mon_1');
} catch (InvalidArgumentException $e) {
    $third = str_contains($e->getMessage(), 'both attacks');
}
ok('a third is refused, and says why', $third);

// The whole point of counting up rather than down.
$dodged = $doDodge->invoke($engine, board(5));
$after = false;
try {
    $doAttack->invoke($engine, $dodged, 'mon_1');
} catch (InvalidArgumentException $e) {
    $after = str_contains($e->getMessage(), 'Already acted');
}
ok('a fighter who dodged cannot then attack twice', $after);

$low = board(4);
$low = $doAttack->invoke($engine, $low, 'mon_1');
$again = false;
try {
    $doAttack->invoke($engine, $low, 'mon_1');
} catch (InvalidArgumentException $e) {
    $again = true;
}
ok('a 4th-level fighter gets one swing', $again);

echo "\n== what the board is told ==\n";
$derive = new ReflectionMethod(CombatEngine::class, 'deriveUi');
$derive->setAccessible(true);
$fresh = $derive->invoke($engine, board(5));
same('two, before anything is spent', 2, $fresh['ui']['attacks_left']);
$one = board(5);
$one = $derive->invoke($engine, $doAttack->invoke($engine, $one, 'mon_1'));
same('one, after the first swing', 1, $one['ui']['attacks_left']);

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
