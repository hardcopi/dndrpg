<?php
/**
 * Temporary hit points, stabilising, and being caught unawares.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_wards.php`.
 *
 * Three things the engine could not express, each for its own reason:
 *
 *   - Temporary hit points had no pool and no spell that granted any. The pool
 *     is here now and so is False Life, because a mechanic with no source is
 *     the same dead field as `speed_zero` was.
 *   - Spare the Dying was authored as `heal 1d4`, which was the closest reading
 *     available when there was no such state as stable. Death saves made it the
 *     wrong one — the SRD spell restores no hit points at all.
 *   - `encounters.is_ambush` meant "starts the moment you walk in" and never
 *     meant the other obvious thing, which is that you did not see it coming.
 *
 * Touches no rows.
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

function board(array $pc = []): array
{
    return [
        'round' => 1, 'turn_index' => 0, 'order' => ['pc_1', 'mon_1'], 'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16)), 'w' => 16, 'h' => 12],
        'log' => [], 'events' => [],
        'combatants' => [
            array_merge([
                'character_id' => 0,
                'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'name' => 'Wren',
                'class' => 'Fighter', 'level' => 3, 'x' => 4, 'y' => 5,
                'str' => 14, 'dex' => 12, 'con' => 14, 'int' => 10, 'wis' => 10, 'cha' => 10,
                'prof' => 2, 'speed' => 30, 'move_left' => 30,
                'hp' => 20, 'max_hp' => 20, 'ac' => 15, 'alive' => true,
                'conditions' => [], 'boons' => [], 'feats' => [], 'concentrating' => null,
                'stable' => false, 'death_saves' => ['success' => 0, 'fail' => 0],
                'temp_hp' => 0, 'surprised' => false,
            ], $pc),
            ['cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
             'x' => 5, 'y' => 5, 'hp' => 11, 'max_hp' => 11, 'ac' => 12, 'alive' => true,
             'conditions' => [], 'boons' => [], 'concentrating' => null,
             'temp_hp' => 0, 'surprised' => false],
        ],
    ];
}
$who = fn(array $s) => $s['combatants'][0];

echo "== a ward takes the blow first ==\n";
$warded = $who($invoke('takeDamage', board(['temp_hp' => 8]), 'pc_1', 5));
same('five against eight leaves three of the ward', 3, (int) $warded['temp_hp']);
same('and the hit points are untouched', 20, (int) $warded['hp']);

$broken = $who($invoke('takeDamage', board(['temp_hp' => 8]), 'pc_1', 12));
same('twelve breaks it', 0, (int) $broken['temp_hp']);
same('and four reaches the hit points', 16, (int) $broken['hp']);

// A blow entirely soaked must not reach anything underneath: no concentration
// check, and nobody dropped. This is why the ward is spent before the
// subtraction rather than inside it.
$conc = board(['temp_hp' => 10, 'concentrating' => ['spell_id' => 1, 'name' => 'Bless']]);
$held = $invoke('takeDamage', $conc, 'pc_1', 4);
ok('a blow the ward eats whole raises no concentration check',
    is_array($who($held)['concentrating'] ?? null));

$grazed = $who($invoke('takeDamage', board(['temp_hp' => 3, 'hp' => 2]), 'pc_1', 3));
ok('and cannot drop somebody', !empty($grazed['alive']) && (int) $grazed['hp'] === 2);

echo "\n== granting one ==\n";
$given = $who($invoke('grantTempHp', board(), 'pc_1', 7));
same('a fresh ward is taken', 7, (int) $given['temp_hp']);
$bigger = $who($invoke('grantTempHp', board(['temp_hp' => 4]), 'pc_1', 9));
same('a stronger one replaces it', 9, (int) $bigger['temp_hp']);
$smaller = $who($invoke('grantTempHp', board(['temp_hp' => 9]), 'pc_1', 4));
same('a weaker one is refused rather than stacking', 9, (int) $smaller['temp_hp']);
// They do not heal, and healing does not restore them.
$healed = $who($invoke('heal', board(['temp_hp' => 5, 'hp' => 10]), 'pc_1', 4));
same('healing leaves the ward where it was', 5, (int) $healed['temp_hp']);
same('and puts the hit points back', 14, (int) $healed['hp']);

echo "\n== False Life exists to grant one ==\n";
$row = db()->query("SELECT resolution, damage_dice, effect_json FROM spells WHERE spell_key='false_life'")->fetch();
ok('the spell is in the content', (bool) $row);
same('it heals nothing', 'heal', (string) ($row['resolution'] ?? ''));
ok('and says the number is temporary', str_contains((string) ($row['effect_json'] ?? ''), 'temp_hp'));

echo "\n== Spare the Dying stabilises, and does not heal ==\n";
$std = db()->query("SELECT damage_dice, effect_json FROM spells WHERE spell_key='spare_the_dying'")->fetch();
ok('it rolls no dice any more', ($std['damage_dice'] ?? null) === null);
ok('and asks to stabilise', str_contains((string) ($std['effect_json'] ?? ''), 'stabilise'));

$dying = board(['hp' => 0, 'conditions' => [['key' => 'unconscious']],
                'death_saves' => ['success' => 1, 'fail' => 2]]);
$steadied = $who($invoke('stabilise', $dying, 'pc_1'));
ok('a dying character is stabilised', !empty($steadied['stable']));
same('with no hit points restored', 0, (int) $steadied['hp']);
ok('and stays unconscious', Conditions::has($steadied, 'unconscious'));
same('the slate is wiped', ['success' => 0, 'fail' => 0], $steadied['death_saves']);

$fine = $who($invoke('stabilise', board(), 'pc_1'));
ok('somebody on their feet cannot be stabilised', empty($fine['stable']));

echo "\n== surprise ==\n";
$ambushed = board(['surprised' => true]);
$after = $invoke('beginTurn', $ambushed);
$w = $who($after);
ok('a surprised character loses the turn',
    (int) $after['turn_index'] !== 0 || ($after['status'] ?? '') !== 'active');
ok('the flag is spent by the turn it cost', empty($w['surprised']));
ok('and no reaction is held either — no opportunity attacks while reeling',
    !empty($w['reaction_used']));
ok('an event is emitted for the board',
    count(array_filter($after['events'] ?? [], fn($e) => $e['type'] === 'surprised')) === 1);

$ready = $invoke('beginTurn', board());
same('an unsurprised character keeps their turn', 0, (int) $ready['turn_index']);

// Who is surprised is a per-character question, not a party-wide one. The DC
// is the only invented number in the rule, and the band matters: a cellar of
// rats must not surprise somebody who is awake, and the worst thing in the
// game must not be unnoticeable.
same('rats and worse ask for ten', 10, CombatEngine::surpriseDc(0.0));
same('a CR 3 asks for thirteen', 13, CombatEngine::surpriseDc(3.0));
same('and it stops at sixteen', 16, CombatEngine::surpriseDc(9.0));
ok('a watchful character notices a weak ambush',
    Rules::passiveScore(['class' => 'Ranger', 'level' => 3, 'wis' => 16,
                         'background' => 'Guide'], 'perception') >= CombatEngine::surpriseDc(0.0));

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
